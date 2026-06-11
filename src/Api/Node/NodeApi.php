<?php

namespace ItHealer\LaravelEvm\Api\Node;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Api\Node\DTO\FeeEstimateDTO;
use ItHealer\LaravelEvm\Api\Node\DTO\PreviewTransferDTO;
use ItHealer\LaravelEvm\Api\Node\DTO\TransferDTO;
use ItHealer\LaravelEvm\Enums\TxType;
use ItHealer\LaravelEvm\Exceptions\TransferException;
use ItHealer\LaravelEvm\Support\ProxyFormatter;
use ItHealer\LaravelEvm\Tx\Eip1559TransactionSigner;
use ItHealer\LaravelEvm\Tx\LegacyTransactionSigner;
use ItHealer\LaravelEvm\Tx\Support\Hex;
use ItHealer\LaravelEvm\Tx\TransactionSignerInterface;

class NodeApi
{
    protected ?string $proxy;
    protected array $tokenDecimals = [];
    protected ?TxType $resolvedTxType = null;

    public function __construct(
        protected string $baseURL,
        protected int $chainId,
        protected int $nativeDecimals = 18,
        ?string $proxy = null,
        protected ?TxType $txType = null,
    ) {
        $this->proxy = ProxyFormatter::format($proxy);
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    /**
     * Отправка транзакции с безопасным выделением nonce.
     *
     * Нода может не отражать только что отправленные транзакции в
     * eth_getTransactionCount(pending) (лаг мемпула, балансировщик нод),
     * из-за чего последовательные транзакции получают одинаковый nonce и
     * вытесняют друг друга из мемпула. Поэтому:
     *  - отправки с одного адреса (в рамках одной сети) сериализуются через Cache::lock;
     *  - используется max(nonce ноды, локально зарезервированный nonce);
     *  - после успешной отправки следующий nonce запоминается в кэше
     *    (TTL 10 минут — страховка на случай вытеснения транзакции).
     *
     * @param  callable(int $nonce): string  $buildRawTransaction  возвращает подписанную raw-транзакцию ('0x...')
     */
    protected function sendWithSafeNonce(string $from, callable $buildRawTransaction): string
    {
        $from = Str::lower($from);
        $lock = Cache::lock("evm:transfer-lock:{$this->chainId}:{$from}", 60);

        return $lock->block(60, function () use ($from, $buildRawTransaction): string {
            $chainNonce = Hex::toInt($this->rpc('eth_getTransactionCount', [$from, 'pending']));
            $localNonce = (int) Cache::get("evm:next-nonce:{$this->chainId}:{$from}", 0);
            $nonce = max($chainNonce, $localNonce);

            $raw = $buildRawTransaction($nonce);
            $txid = $this->rpc('eth_sendRawTransaction', [$raw]);

            Cache::put("evm:next-nonce:{$this->chainId}:{$from}", $nonce + 1, now()->addMinutes(10));

            return $txid;
        });
    }

    public function rpc(string $method, array $params = []): mixed
    {
        $client = Http::asJson()
            ->acceptJson()
            ->withOptions([
                'base_uri' => $this->baseURL,
                'timeout' => 60,
                'proxy' => $this->proxy,
            ]);

        $response = $client->post('', [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ]);

        $result = $response->json();

        if (isset($result['error'])) {
            throw new \Exception($result['error']['message']);
        }

        if (count($result ?? []) === 0 || !array_key_exists('result', $result)) {
            throw new \Exception($response->body());
        }

        return $result['result'];
    }

    public function getBalance(string $address): BigDecimal
    {
        $balanceHex = $this->rpc('eth_getBalance', [$address, 'latest']);

        return Hex::toBigDecimal($balanceHex, $this->nativeDecimals);
    }

    public function getTokenName(string $contract): ?string
    {
        $hex = $this->rpc('eth_call', [
            ['to' => $contract, 'data' => '0x06fdde03'],
            'latest',
        ]);

        return trim(hex2bin(substr($hex, 130)), "\0");
    }

    public function getTokenSymbol(string $contract): ?string
    {
        $hex = $this->rpc('eth_call', [
            ['to' => $contract, 'data' => '0x95d89b41'],
            'latest',
        ]);

        return trim(hex2bin(substr($hex, 130)), "\0");
    }

    public function getTokenDecimals(string $contract): int
    {
        $hex = $this->rpc('eth_call', [
            ['to' => $contract, 'data' => '0x313ce567'],
            'latest',
        ]);

        return Hex::toInt($hex);
    }

    public function getBalanceOfToken(string $address, string $contract): BigDecimal
    {
        $decimals = $this->tokenDecimals[$contract] ??= $this->getTokenDecimals($contract);

        $data = '0x70a08231000000000000000000000000'.substr(Str::lower($address), 2);
        $balanceHex = $this->rpc('eth_call', [
            ['to' => $contract, 'data' => $data],
            'latest',
        ]);

        return Hex::toBigDecimal($balanceHex, $decimals);
    }

    public function getLatestBlockNumber(): int
    {
        return Hex::toInt($this->rpc('eth_blockNumber'));
    }

    public function getLatestBlock(): array
    {
        return $this->rpc('eth_getBlockByNumber', ['latest', false]);
    }

    public function gasPrice(): BigDecimal
    {
        return Hex::toBigDecimal($this->rpc('eth_gasPrice'));
    }

    /**
     * Suggested priority fee (wei). Falls back to the configured
     * value when the node does not support eth_maxPriorityFeePerGas.
     */
    public function maxPriorityFeePerGas(): BigDecimal
    {
        try {
            return Hex::toBigDecimal($this->rpc('eth_maxPriorityFeePerGas'));
        } catch (\Exception) {
            return BigDecimal::of((string)config('evm.fee.fallback_priority_fee_gwei', '1.5'))
                ->withPointMovedRight(9)
                ->toScale(0);
        }
    }

    /**
     * Effective transaction type of this network: the explicitly configured
     * one, otherwise auto-detected by baseFeePerGas presence in the latest block.
     */
    public function resolveTxType(): TxType
    {
        if ($this->txType !== null) {
            return $this->txType;
        }

        if ($this->resolvedTxType === null) {
            $block = $this->getLatestBlock();
            $this->resolvedTxType = isset($block['baseFeePerGas']) ? TxType::Eip1559 : TxType::Legacy;
        }

        return $this->resolvedTxType;
    }

    public function estimateFees(): FeeEstimateDTO
    {
        $txType = $this->resolveTxType();

        if ($txType === TxType::Legacy) {
            return FeeEstimateDTO::make([
                'tx_type' => TxType::Legacy->value,
                'gas_price' => $this->gasPrice()->__toString(),
            ]);
        }

        $block = $this->getLatestBlock();
        $baseFee = Hex::toBigDecimal($block['baseFeePerGas'] ?? '0x0');
        $priorityFee = $this->maxPriorityFeePerGas();
        $multiplier = (string)config('evm.fee.base_fee_multiplier', 2);
        $maxFee = $baseFee->multipliedBy($multiplier)->plus($priorityFee)->toScale(0);

        return FeeEstimateDTO::make([
            'tx_type' => TxType::Eip1559->value,
            'base_fee' => $baseFee->__toString(),
            'max_priority_fee_per_gas' => $priorityFee->__toString(),
            'max_fee_per_gas' => $maxFee->__toString(),
        ]);
    }

    public function gasEstimate(string $from, string $to, string $data = '', ?BigDecimal $valueWei = null): BigDecimal
    {
        $params = ['from' => $from, 'to' => $to];
        if ($data !== '' && $data !== '0x') {
            $params['data'] = $data;
        }
        if ($valueWei !== null && !$valueWei->isZero()) {
            $params['value'] = '0x'.Hex::fromBigDecimal($valueWei);
        }

        return Hex::toBigDecimal($this->rpc('eth_estimateGas', [$params]));
    }

    protected function amountToWei(BigDecimal $amount, int $decimals): BigDecimal
    {
        return $amount->withPointMovedRight($decimals)->toScale(0);
    }

    protected function signer(TxType $txType): TransactionSignerInterface
    {
        return $txType === TxType::Legacy
            ? new LegacyTransactionSigner()
            : new Eip1559TransactionSigner();
    }

    /**
     * @return array{0: array, 1: array} [DTO fee attributes, signer fees (hex)]
     */
    protected function feeAttributes(FeeEstimateDTO $fees): array
    {
        if ($fees->txType() === TxType::Legacy) {
            return [
                [
                    'tx_type' => TxType::Legacy->value,
                    'gas_price' => $fees->gasPrice()->__toString(),
                    'max_fee_per_gas' => null,
                    'max_priority_fee_per_gas' => null,
                ],
                ['gas_price' => Hex::fromBigDecimal($fees->gasPrice())],
            ];
        }

        return [
            [
                'tx_type' => TxType::Eip1559->value,
                'gas_price' => $fees->maxFeePerGas()->__toString(),
                'max_fee_per_gas' => $fees->maxFeePerGas()->__toString(),
                'max_priority_fee_per_gas' => $fees->maxPriorityFeePerGas()->__toString(),
            ],
            [
                'max_fee_per_gas' => Hex::fromBigDecimal($fees->maxFeePerGas()),
                'max_priority_fee_per_gas' => Hex::fromBigDecimal($fees->maxPriorityFeePerGas()),
            ],
        ];
    }

    public function previewTransfer(
        string $from,
        string $to,
        BigDecimal $amount,
        ?BigDecimal $balanceBefore = null,
        ?int $gasLimit = null
    ): PreviewTransferDTO {
        $from = Str::lower($from);
        $to = Str::lower($to);

        $valueWei = $this->amountToWei($amount, $this->nativeDecimals);

        $fees = $this->estimateFees();
        $gasEstimate = $this->gasEstimate($from, $to, valueWei: $valueWei);
        if ($gasLimit) {
            $gasLimit = BigDecimal::of($gasLimit);
            $gasEstimate = $gasLimit->isLessThan($gasEstimate) ? $gasLimit : $gasEstimate;
        }
        $fee = $fees->effectiveGasPrice()
            ->multipliedBy($gasEstimate)
            ->dividedBy(BigDecimal::one()->withPointMovedRight($this->nativeDecimals), $this->nativeDecimals);

        if ($balanceBefore === null) {
            $balanceBefore = $this->getBalance($from);
        }
        $balanceAfter = $balanceBefore->minus($fee)->minus($amount);

        $error = null;
        if ($balanceAfter->isNegative()) {
            $error = 'Insufficient native balance';
        }

        [$feeAttributes] = $this->feeAttributes($fees);

        return PreviewTransferDTO::make([
            'from' => $from,
            'to' => $to,
            'amount' => $amount->__toString(),
            'data' => '',
            ...$feeAttributes,
            'gas_limit' => $gasEstimate->__toString(),
            'fee' => $fee->__toString(),
            'balance_before' => $balanceBefore->__toString(),
            'balance_after' => $balanceAfter->__toString(),
            'error' => $error,
        ]);
    }

    public function transfer(
        string $from,
        string $to,
        string $privateKey,
        BigDecimal $amount,
        ?BigDecimal $balanceBefore = null,
        ?int $gasLimit = null
    ): TransferDTO {
        $preview = $this->previewTransfer($from, $to, $amount, $balanceBefore, $gasLimit);
        if ($preview->hasError()) {
            throw new TransferException($preview->error());
        }

        $txid = $this->sendToChain(
            from: $preview->from(),
            to: $preview->to(),
            valueWei: $this->amountToWei($amount, $this->nativeDecimals),
            data: '',
            preview: $preview,
            privateKey: $privateKey,
        );

        return TransferDTO::make([
            ...$preview->toArray(),
            'txid' => $txid,
        ]);
    }

    public function previewTokenTransfer(
        string $contract,
        string $from,
        string $to,
        BigDecimal $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null,
    ): PreviewTransferDTO {
        $contract = Str::lower($contract);
        $from = Str::lower($from);
        $to = Str::lower($to);

        $decimals = $this->tokenDecimals[$contract] ??= $this->getTokenDecimals($contract);

        if ($tokenBalanceBefore === null) {
            $tokenBalanceBefore = $this->getBalanceOfToken($from, $contract);
        }
        $tokenBalanceAfter = $tokenBalanceBefore->minus($amount);

        if ($balanceBefore === null) {
            $balanceBefore = $this->getBalance($from);
        }

        if ($tokenBalanceAfter->isNegative()) {
            return PreviewTransferDTO::make([
                'contract' => $contract,
                'from' => $from,
                'to' => $to,
                'amount' => $amount->__toString(),
                'data' => '',
                'tx_type' => $this->resolveTxType()->value,
                'gas_price' => 0,
                'max_fee_per_gas' => null,
                'max_priority_fee_per_gas' => null,
                'gas_limit' => 0,
                'fee' => 0,
                'balance_before' => $balanceBefore->__toString(),
                'balance_after' => $balanceBefore->__toString(),
                'token_balance_before' => $tokenBalanceBefore->__toString(),
                'token_balance_after' => $tokenBalanceAfter->__toString(),
                'error' => 'Insufficient token balance',
            ]);
        }

        $data = '0xa9059cbb'
            .str_pad(substr($to, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(
                $this->amountToWei($amount, $decimals)->toBigInteger()->toBase(16),
                64,
                '0',
                STR_PAD_LEFT
            );

        return $this->previewContractCall(
            contract: $contract,
            from: $from,
            to: $to,
            amount: $amount,
            data: $data,
            balanceBefore: $balanceBefore,
            tokenBalanceBefore: $tokenBalanceBefore,
            tokenBalanceAfter: $tokenBalanceAfter,
            gasLimit: $gasLimit,
        );
    }

    public function transferToken(
        string $contract,
        string $from,
        string $to,
        string $privateKey,
        BigDecimal $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null,
    ): TransferDTO {
        $preview = $this->previewTokenTransfer($contract, $from, $to, $amount, $balanceBefore, $tokenBalanceBefore, $gasLimit);
        if ($preview->hasError()) {
            throw new TransferException($preview->error());
        }

        $txid = $this->sendToChain(
            from: $preview->from(),
            to: $preview->contract(),
            valueWei: BigDecimal::zero(),
            data: $preview->data(),
            preview: $preview,
            privateKey: $privateKey,
        );

        return TransferDTO::make([
            ...$preview->toArray(),
            'txid' => $txid,
        ]);
    }

    public function previewTransferFromToken(
        string $contract,
        string $from,
        string $to,
        BigDecimal $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null
    ): PreviewTransferDTO {
        $contract = Str::lower($contract);
        $from = Str::lower($from);
        $to = Str::lower($to);

        $decimals = $this->tokenDecimals[$contract] ??= $this->getTokenDecimals($contract);

        if ($tokenBalanceBefore === null) {
            $tokenBalanceBefore = $this->getBalanceOfToken($from, $contract);
        }
        $tokenBalanceAfter = $tokenBalanceBefore->minus($amount);

        if ($balanceBefore === null) {
            $balanceBefore = $this->getBalance($from);
        }

        if ($tokenBalanceAfter->isNegative()) {
            return PreviewTransferDTO::make([
                'contract' => $contract,
                'from' => $from,
                'to' => $to,
                'amount' => $amount->__toString(),
                'data' => '',
                'tx_type' => $this->resolveTxType()->value,
                'gas_price' => 0,
                'max_fee_per_gas' => null,
                'max_priority_fee_per_gas' => null,
                'gas_limit' => 0,
                'fee' => 0,
                'balance_before' => $balanceBefore->__toString(),
                'balance_after' => $balanceBefore->__toString(),
                'token_balance_before' => $tokenBalanceBefore->__toString(),
                'token_balance_after' => $tokenBalanceAfter->__toString(),
                'error' => 'Insufficient token balance',
            ]);
        }

        $data = '0x23b872dd'
            .str_pad(substr($from, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(substr($to, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(
                $this->amountToWei($amount, $decimals)->toBigInteger()->toBase(16),
                64,
                '0',
                STR_PAD_LEFT
            );

        return $this->previewContractCall(
            contract: $contract,
            from: $from,
            to: $to,
            amount: $amount,
            data: $data,
            balanceBefore: $balanceBefore,
            tokenBalanceBefore: $tokenBalanceBefore,
            tokenBalanceAfter: $tokenBalanceAfter,
            gasLimit: $gasLimit,
        );
    }

    public function transferFromToken(
        string $contract,
        string $from,
        string $to,
        string $privateKey,
        BigDecimal $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null
    ): TransferDTO {
        $preview = $this->previewTransferFromToken($contract, $from, $to, $amount, $balanceBefore, $tokenBalanceBefore, $gasLimit);
        if ($preview->hasError()) {
            throw new TransferException($preview->error());
        }

        // Gas is paid by the collector address that signs the transaction,
        // so the nonce is allocated for the signer derived from $privateKey.
        $txid = $this->sendToChain(
            from: Str::lower(\ItHealer\LaravelEvm\Tx\Support\Key::privateKeyToAddress($privateKey)),
            to: $preview->contract(),
            valueWei: BigDecimal::zero(),
            data: $preview->data(),
            preview: $preview,
            privateKey: $privateKey,
        );

        return TransferDTO::make([
            ...$preview->toArray(),
            'txid' => $txid,
        ]);
    }

    protected function previewContractCall(
        string $contract,
        string $from,
        string $to,
        BigDecimal $amount,
        string $data,
        BigDecimal $balanceBefore,
        BigDecimal $tokenBalanceBefore,
        BigDecimal $tokenBalanceAfter,
        ?int $gasLimit = null,
    ): PreviewTransferDTO {
        $fees = $this->estimateFees();
        $gasEstimate = $this->gasEstimate($from, $contract, $data);
        if ($gasLimit) {
            $gasLimit = BigDecimal::of($gasLimit);
            $gasEstimate = $gasLimit->isLessThan($gasEstimate) ? $gasLimit : $gasEstimate;
        }
        $fee = $fees->effectiveGasPrice()
            ->multipliedBy($gasEstimate)
            ->dividedBy(BigDecimal::one()->withPointMovedRight($this->nativeDecimals), $this->nativeDecimals);
        $balanceAfter = $balanceBefore->minus($fee);

        $error = null;
        if ($balanceAfter->isNegative()) {
            $error = 'Insufficient native balance';
        }

        [$feeAttributes] = $this->feeAttributes($fees);

        return PreviewTransferDTO::make([
            'contract' => $contract,
            'from' => $from,
            'to' => $to,
            'amount' => $amount->__toString(),
            'data' => $data,
            ...$feeAttributes,
            'gas_limit' => $gasEstimate->__toString(),
            'fee' => $fee->__toString(),
            'balance_before' => $balanceBefore->__toString(),
            'balance_after' => $balanceAfter->__toString(),
            'token_balance_before' => $tokenBalanceBefore->__toString(),
            'token_balance_after' => $tokenBalanceAfter->__toString(),
            'error' => $error,
        ]);
    }

    protected function sendToChain(
        string $from,
        string $to,
        BigDecimal $valueWei,
        string $data,
        PreviewTransferDTO $preview,
        string $privateKey,
    ): string {
        $txType = $preview->txType();
        $signer = $this->signer($txType);

        $signerFees = $txType === TxType::Legacy
            ? ['gas_price' => Hex::fromBigDecimal($preview->gasPrice())]
            : [
                'max_fee_per_gas' => Hex::fromBigDecimal($preview->maxFeePerGas()),
                'max_priority_fee_per_gas' => Hex::fromBigDecimal($preview->maxPriorityFeePerGas()),
            ];

        $valueHex = $valueWei->isZero() ? '' : Hex::fromBigDecimal($valueWei);
        $gasLimitHex = Hex::fromBigDecimal($preview->gasLimit());

        return $this->sendWithSafeNonce($from, function (int $nonce) use (
            $signer,
            $to,
            $valueHex,
            $data,
            $gasLimitHex,
            $signerFees,
            $privateKey
        ): string {
            return $signer->sign(
                chainId: $this->chainId,
                nonce: $nonce,
                to: $to,
                valueHex: $valueHex,
                dataHex: $data,
                gasLimitHex: $gasLimitHex,
                fees: $signerFees,
                privateKey: $privateKey,
            );
        });
    }
}
