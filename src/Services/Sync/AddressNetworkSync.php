<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Api\Node\NodeApi;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Enums\TransactionType;
use ItHealer\LaravelEvm\Explorer\Contracts\ExplorerDriverInterface;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTokenTransactionDTO;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTransactionDTO;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmAddressBalance;
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Models\EvmExplorer;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Models\EvmToken;
use ItHealer\LaravelEvm\Models\EvmWallet;
use ItHealer\LaravelEvm\Services\BaseSync;
use ItHealer\LaravelEvm\Webhook\WebhookHandlerInterface;

/**
 * Synchronizes one address within one network: native balance,
 * token balances, transaction history and deposit webhooks.
 */
class AddressNetworkSync extends BaseSync
{
    protected EvmWallet $wallet;
    protected EvmNode $node;
    protected NodeApi $nodeApi;
    protected EvmExplorer $explorer;
    protected ExplorerDriverInterface $explorerApi;
    protected EvmAddressBalance $balanceRow;
    /** @var array<string, EvmToken> */
    protected array $tokens;
    protected bool $touchEnabled;
    protected int $touchPeriod;
    protected ?WebhookHandlerInterface $webhookHandler;
    /** @var array<EvmDeposit> */
    protected array $webhooks = [];
    protected int $blockNumber;

    public function __construct(
        protected EvmAddress $address,
        protected EvmNetwork $network,
        protected bool $force = false,
    ) {
        $this->wallet = $address->wallet;
        $this->node = Evm::getNode($network);
        $this->nodeApi = $this->node->api();
        $this->explorer = Evm::getExplorer($network);
        $this->explorerApi = $this->explorer->api();
        $this->balanceRow = $address->balanceForNetwork($network);

        $this->tokens = $network->tokens()
            ->where('active', true)
            ->get()
            ->keyBy(fn (EvmToken $item) => Str::lower($item->address))
            ->all();

        $this->touchEnabled = (bool)config('evm.touch.enabled', false);
        $this->touchPeriod = (int)config('evm.touch.waiting_seconds', 3600);

        $webhookHandler = config('evm.webhook_handler');
        $this->webhookHandler = $webhookHandler ? App::make($webhookHandler) : null;

        $this->blockNumber = $this->latestBlockNumber();
    }

    /**
     * Latest block number, optionally cached per network for `evm.sync.block_cache_ttl`
     * seconds so a multi-address run does not pay for one eth_blockNumber per address.
     */
    protected function latestBlockNumber(): int
    {
        $ttl = (int)config('evm.sync.block_cache_ttl', 0);

        if ($ttl <= 0) {
            return $this->node->getLatestBlockNumber();
        }

        return (int)Cache::remember(
            'evm:latest-block:'.$this->network->id,
            $ttl,
            fn (): int => $this->node->getLatestBlockNumber(),
        );
    }

    /**
     * Counts an explorer request and the Compute Units it costs.
     */
    protected function explorerOnRequest(): Closure
    {
        $credits = $this->explorerApi->creditsPerRequest();

        return function () use ($credits): void {
            $this->explorer->increment('requests');
            $this->explorer->recordCredits($credits);
        };
    }

    public function run(): void
    {
        parent::run();

        if (!$this->address->available) {
            $this->log('No synchronization required, the address has not been available!', 'success');
            return;
        }

        if ($this->touchEnabled && !$this->force && $this->shouldSkipBySchedule()) {
            $this->log('No full synchronization by the adaptive touch schedule; reconciling pending only.', 'success');
            $this->reconcilePending();
            return;
        }

        $this
            ->balance()
            ->tokenBalances()
            ->transactions()
            ->reconcilePending()
            ->runWebhooks();
    }

    /**
     * Resolve broadcast-but-still-pending outgoing transfers so a stuck/replaced/dropped
     * transaction stops being subtracted from the available balance forever.
     *
     *  - nonce already below the confirmed account nonce: the slot was settled, so the
     *    transfer was mined (stamp block_number) or replaced/dropped (mark dropped_at).
     *  - nonce still next (>= confirmed): ask the node whether it still knows the txid —
     *    a mined block stamps it, an unknown txid (after a short grace) means it was
     *    evicted from the mempool and is dropped, otherwise it is genuinely stuck and kept.
     *  - `ttl_minutes` remains a last-resort fallback.
     */
    protected function reconcilePending(): static
    {
        /** @var class-string<\ItHealer\LaravelEvm\Models\EvmTransaction> $transactionModel */
        $transactionModel = Evm::getModel(EvmModel::Transaction);

        $pending = $transactionModel::query()
            ->pendingOutgoing()
            ->where('network_id', $this->network->id)
            ->where('address', $this->address->address)
            ->get();

        if ($pending->isEmpty()) {
            return $this;
        }

        $confirmedNonce = $this->nodeApi->getConfirmedNonce($this->address->address);

        $now = Date::now();

        $ttlMinutes = config('evm.pending.ttl_minutes');
        $ttlThreshold = $ttlMinutes !== null
            ? $now->copy()->subMinutes((int) $ttlMinutes)
            : null;

        $dropGraceSeconds = (int) config('evm.pending.dropped_grace_seconds', 60);
        $dropGraceThreshold = $now->copy()->subSeconds($dropGraceSeconds);

        foreach ($pending as $transaction) {
            if ($transaction->nonce !== null && $transaction->nonce < $confirmedNonce) {
                $blockNumber = $this->nodeApi->getTransactionBlockNumber($transaction->txid);

                if ($blockNumber !== null) {
                    $this->log("Pending transfer {$transaction->txid} confirmed in block {$blockNumber}.", 'success');
                    $transaction->update(['block_number' => $blockNumber]);
                } else {
                    $this->log("Pending transfer {$transaction->txid} dropped/replaced (nonce {$transaction->nonce} < {$confirmedNonce}).", 'success');
                    $transaction->update(['dropped_at' => $now]);
                }

                continue;
            }

            if ($transaction->nonce !== null) {
                $known = $this->nodeApi->getTransactionByHash($transaction->txid);

                if ($known !== null && $known['blockNumber'] !== null) {
                    $this->log("Pending transfer {$transaction->txid} confirmed in block {$known['blockNumber']}.", 'success');
                    $transaction->update(['block_number' => $known['blockNumber']]);

                    continue;
                }

                if ($known === null
                    && (! $transaction->time_at || $transaction->time_at < $dropGraceThreshold)) {
                    $this->log("Pending transfer {$transaction->txid} evicted from the mempool, dropping.", 'success');
                    $transaction->update(['dropped_at' => $now]);

                    continue;
                }
            }

            if ($ttlThreshold !== null && $transaction->time_at && $transaction->time_at < $ttlThreshold) {
                $this->log("Pending transfer {$transaction->txid} expired by TTL.", 'success');
                $transaction->update(['dropped_at' => $now]);
            }
        }

        return $this;
    }

    /**
     * Adaptive touch schedule: an address is "active" for `waiting_seconds` after its last
     * touch (user/merchant activity). While active it syncs no more often than `fast_interval`;
     * while idle, no more often than `slow_interval` (null = skip idle addresses entirely).
     */
    protected function shouldSkipBySchedule(): bool
    {
        $now = Date::now();

        $activeWindow = (int) config('evm.touch.waiting_seconds', 3600);
        $isActive = ! $this->address->touch_at
            || $this->address->touch_at >= $now->copy()->subSeconds($activeWindow);

        if ($isActive) {
            $interval = (int) config('evm.touch.fast_interval', 0);
        } else {
            $slowInterval = config('evm.touch.slow_interval');

            if ($slowInterval === null) {
                return true;
            }

            $interval = (int) $slowInterval;
        }

        return $interval > 0
            && $this->balanceRow->sync_at
            && $this->balanceRow->sync_at >= $now->copy()->subSeconds($interval);
    }

    protected function balance(): static
    {
        $symbol = $this->network->currency_symbol;

        $this->log("Starting {$symbol} Balance of address *{$this->address->address}* ...");
        $balance = $this->node->getBalance($this->address);
        $this->log("Finished {$symbol} Balance of address *{$this->address->address}*: ".$balance->__toString());

        $this->balanceRow->update([
            'balance' => $balance,
            'sync_at' => Date::now(),
        ]);

        $this->address->update([
            'touch_at' => $this->address->touch_at ?: Date::now(),
            'sync_at' => Date::now(),
        ]);

        return $this;
    }

    protected function tokenBalances(): static
    {
        $tokensBalances = [];

        foreach ($this->tokens as $token) {
            $this->log('Get ERC-20 Balance from contract *'.$token->address.'* started...');
            $balance = $this->node->getBalanceOfToken($this->address, $token);
            $this->log(
                'Get ERC-20 Balance from contract *'.$token->address.'* finished: '.$balance->__toString(),
                'success'
            );

            $tokensBalances[$token->address] = $balance->__toString();
        }

        $this->balanceRow->update([
            'tokens' => $tokensBalances,
            'sync_at' => Date::now(),
        ]);

        return $this;
    }

    protected function transactions(): static
    {
        $startBlock = $this->balanceRow->sync_block_number ?? 0;

        $paginator = $this->explorerApi->getNativeTransactions(
            address: $this->address->address,
            startBlock: $startBlock,
            perPage: 100,
            onRequest: $this->explorerOnRequest()
        );

        foreach ($paginator as $item) {
            $this->checkCancelled();
            $this->handleTransaction($item);
            $this->reportProgress('transactions');
        }

        if (count($this->tokens) > 0) {
            $paginator = $this->explorerApi->getTokenTransactions(
                address: $this->address->address,
                contract: null,
                startBlock: $startBlock,
                perPage: 100,
                onRequest: $this->explorerOnRequest()
            );

            foreach ($paginator as $item) {
                $this->checkCancelled();
                $this->handleTokenTransaction($item);
                $this->reportProgress('token_transactions');
            }
        }

        /*
         * Explorer indexes transactions with a delay: a transaction already mined by the
         * node may not be returned by the explorer yet. Keep an overlap of `lag_blocks`
         * behind the node head so the next sync re-checks recent blocks instead of
         * skipping them forever. Re-processing is idempotent (updateOrCreate by txid).
         */
        $this->balanceRow->update([
            'sync_at' => Date::now(),
            'sync_block_number' => max(0, $this->blockNumber - $this->network->effectiveLagBlocks()),
        ]);

        return $this;
    }

    protected function handleTransaction(ExplorerTransactionDTO $transaction): void
    {
        if ($transaction->contractAddress() || $transaction->isError()) {
            return;
        }

        $type = $transaction->to() === Str::lower($this->address->address)
            ? TransactionType::INCOMING
            : TransactionType::OUTGOING;

        /** @var class-string<\ItHealer\LaravelEvm\Models\EvmTransaction> $transactionModel */
        $transactionModel = Evm::getModel(EvmModel::Transaction);

        $transactionModel::updateOrCreate([
            'network_id' => $this->network->id,
            'txid' => $transaction->hash(),
            'address' => $this->address->address,
            'token_address' => '',
        ], [
            'type' => $type,
            'time_at' => $transaction->time(),
            'from' => $transaction->from(),
            'to' => $transaction->to(),
            'amount' => $transaction->amount(),
            'block_number' => $transaction->blockNumber(),
            'data' => $transaction->toArray(),
        ]);

        if ($type === TransactionType::INCOMING) {
            $deposit = $this->address
                ->deposits()
                ->updateOrCreate([
                    'network_id' => $this->network->id,
                    'txid' => $transaction->hash(),
                    'token_id' => null,
                ], [
                    'wallet_id' => $this->address->wallet_id,
                    'amount' => $transaction->amount(),
                    'block_number' => $transaction->blockNumber(),
                    'confirmations' => $this->confirmations($transaction),
                    'time_at' => $transaction->time(),
                ]);

            if ($deposit->wasRecentlyCreated) {
                $deposit->setRelation('network', $this->network);
                $deposit->setRelation('wallet', $this->wallet);
                $deposit->setRelation('address', $this->address);

                $this->webhooks[] = $deposit;
            }
        }
    }

    protected function handleTokenTransaction(ExplorerTokenTransactionDTO $transaction): void
    {
        $token = $this->tokens[$transaction->contractAddress()] ?? null;
        if (!$token) {
            return;
        }

        $type = $transaction->to() === Str::lower($this->address->address)
            ? TransactionType::INCOMING
            : TransactionType::OUTGOING;

        /** @var class-string<\ItHealer\LaravelEvm\Models\EvmTransaction> $transactionModel */
        $transactionModel = Evm::getModel(EvmModel::Transaction);

        $transactionModel::updateOrCreate([
            'network_id' => $this->network->id,
            'txid' => $transaction->hash(),
            'address' => $this->address->address,
            'token_address' => $transaction->contractAddress(),
        ], [
            'type' => $type,
            'time_at' => $transaction->time(),
            'from' => $transaction->from(),
            'to' => $transaction->to(),
            'amount' => $transaction->amount(),
            'token_id' => $token->id,
            'block_number' => $transaction->blockNumber(),
            'data' => $transaction->toArray(),
        ]);

        if ($type === TransactionType::INCOMING) {
            /** @var EvmDeposit $deposit */
            $deposit = $this->address
                ->deposits()
                ->updateOrCreate([
                    'network_id' => $this->network->id,
                    'txid' => $transaction->hash(),
                    'token_id' => $token->id,
                ], [
                    'wallet_id' => $this->address->wallet_id,
                    'amount' => $transaction->amount(),
                    'block_number' => $transaction->blockNumber(),
                    'confirmations' => $this->confirmations($transaction),
                    'time_at' => $transaction->time(),
                ]);

            if ($deposit->wasRecentlyCreated) {
                $deposit->setRelation('network', $this->network);
                $deposit->setRelation('wallet', $this->wallet);
                $deposit->setRelation('address', $this->address);
                $deposit->setRelation('token', $token);

                $this->webhooks[] = $deposit;
            }
        }
    }

    /**
     * Some drivers (Alchemy) do not return confirmations —
     * compute them from the latest block number instead.
     */
    protected function confirmations(ExplorerTransactionDTO $transaction): int
    {
        $confirmations = $transaction->confirmations();

        if ($confirmations !== null) {
            return $confirmations;
        }

        return max(0, $this->blockNumber - $transaction->blockNumber());
    }

    protected function runWebhooks(): static
    {
        if ($this->webhookHandler) {
            foreach ($this->webhooks as $item) {
                try {
                    $this->log('Call Webhook Handler for Deposit #'.$item->id.'...');
                    $this->webhookHandler->handle($item);
                    $this->log('Successfully', 'success');
                } catch (\Exception $e) {
                    $this->log('Error: '.$e->getMessage(), 'error');
                }
            }
        }

        return $this;
    }
}
