<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Models\EvmTransaction;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;
use ItHealer\LaravelEvm\Webhook\WebhookHandlerInterface;

uses(RefreshDatabase::class);

class SpyWebhookHandler implements WebhookHandlerInterface
{
    public static array $handled = [];

    public function handle(EvmDeposit $deposit): void
    {
        self::$handled[] = $deposit;
    }
}

/** What the fake node answers: change it to simulate a new transfer */
class SyncChainState
{
    public static string $balance = '0x1bc16d674ec80000'; // 2 ETH

    public static int $usdt = 75_000_000; // 75 USDT

    public static int $nonce = 1;

    /** false — the explorer has not indexed the transfers yet (returns nothing) */
    public static bool $indexed = true;

    /** true — the explorer answers with an API error */
    public static bool $explorerDown = false;

    /** What txlistinternal returns */
    public static array $internal = [];

    /** true — only txlistinternal answers with an API error */
    public static bool $internalDown = false;

    public static function reset(): void
    {
        self::$internal = [];
        self::$internalDown = false;
        self::$balance = '0x1bc16d674ec80000';
        self::$usdt = 75_000_000;
        self::$nonce = 1;
        self::$indexed = true;
        self::$explorerDown = false;
    }
}

const ADDR = '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266';
const USDT = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';

function fakeSyncApis(): void
{
    Http::fake([
        'rpc.test*' => function ($request) {
            $data = $request->data();

            $result = match ($data['method']) {
                'eth_blockNumber' => '0x200', // 512
                'eth_getBalance' => SyncChainState::$balance,
                'eth_getTransactionCount' => '0x'.dechex(SyncChainState::$nonce),
                'eth_getTransactionReceipt' => ['blockNumber' => '0x1f4', 'status' => '0x1'],
                'eth_call' => match (substr($data['params'][0]['data'], 0, 10)) {
                    '0x313ce567' => '0x'.str_pad('6', 64, '0', STR_PAD_LEFT),
                    '0x70a08231' => '0x'.str_pad(dechex(SyncChainState::$usdt), 64, '0', STR_PAD_LEFT),
                },
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        },
        'api.etherscan.io/*' => function ($request) {
            $action = $request['action'] ?? '';

            if ($action === 'getapilimit') {
                return Http::response(['status' => '1', 'message' => 'OK', 'result' => []]);
            }

            if (SyncChainState::$explorerDown) {
                return Http::response(['status' => '0', 'message' => 'NOTOK', 'result' => 'Max rate limit reached']);
            }

            if (!SyncChainState::$indexed) {
                return Http::response(['status' => '0', 'message' => 'No transactions found', 'result' => []]);
            }

            if ($action === 'txlistinternal') {
                if (SyncChainState::$internalDown) {
                    return Http::response(['status' => '0', 'message' => 'NOTOK', 'result' => 'Max rate limit reached']);
                }

                return SyncChainState::$internal === []
                    ? Http::response(['status' => '0', 'message' => 'No transactions found', 'result' => []])
                    : Http::response(['status' => '1', 'message' => 'OK', 'result' => SyncChainState::$internal]);
            }

            if ($action === 'txlist') {
                return Http::response(['status' => '1', 'message' => 'OK', 'result' => [
                    [
                        'hash' => '0xnative-in',
                        'blockNumber' => '100',
                        'timeStamp' => '1700000000',
                        'from' => '0x1111111111111111111111111111111111111111',
                        'to' => ADDR,
                        'value' => '500000000000000000', // 0.5
                        'isError' => '0',
                        'confirmations' => '412',
                        'contractAddress' => '',
                    ],
                    [
                        'hash' => '0xnative-out',
                        'blockNumber' => '110',
                        'timeStamp' => '1700000500',
                        'from' => ADDR,
                        'to' => '0x2222222222222222222222222222222222222222',
                        'value' => '100000000000000000', // 0.1
                        'isError' => '0',
                        'confirmations' => '402',
                        'contractAddress' => '',
                    ],
                ]]);
            }

            // tokentx
            return Http::response(['status' => '1', 'message' => 'OK', 'result' => [
                [
                    'hash' => '0xtoken-in',
                    'blockNumber' => '120',
                    'timeStamp' => '1700001000',
                    'from' => '0x3333333333333333333333333333333333333333',
                    'to' => ADDR,
                    'contractAddress' => USDT,
                    'value' => '25000000', // 25 USDT
                    'tokenDecimal' => '6',
                    'tokenSymbol' => 'USDT',
                    'tokenName' => 'Tether USD',
                    'confirmations' => '392',
                ],
            ]]);
        },
    ]);
}

function syncSetup(): array
{
    fakeSyncApis();
    SyncChainState::reset();
    SpyWebhookHandler::$handled = [];
    config()->set('evm.webhook_handler', SpyWebhookHandler::class);

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    Evm::createNode($network, 'node', 'https://rpc.test');
    Evm::createExplorer($network, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'KEY');
    $network->tokens()->create([
        'address' => USDT,
        'name' => 'Tether USD',
        'symbol' => 'USDT',
        'decimals' => 6,
    ]);

    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');
    Evm::attachNetwork($wallet, $network);

    return [$network, $wallet->addresses->first()];
}

it('synchronizes balances, transactions and deposits of an address', function () {
    [$network, $address] = syncSetup();

    (new AddressNetworkSync($address, $network))->run();

    $balanceRow = $address->balanceForNetwork($network)->fresh();

    expect($balanceRow->balance->isEqualTo('2'))->toBeTrue()
        ->and($balanceRow->tokens)->toBe([USDT => '75.000000'])
        ->and($balanceRow->sync_block_number)->toBe(512 - 20)
        ->and(EvmTransaction::count())->toBe(3)
        ->and(EvmDeposit::count())->toBe(2);

    $nativeDeposit = EvmDeposit::whereNull('token_id')->first();
    $tokenDeposit = EvmDeposit::whereNotNull('token_id')->first();

    expect($nativeDeposit->amount->isEqualTo('0.5'))->toBeTrue()
        ->and($nativeDeposit->confirmations)->toBe(412)
        ->and($nativeDeposit->symbol)->toBe('POL')
        ->and($tokenDeposit->amount->isEqualTo('25'))->toBeTrue()
        ->and($tokenDeposit->symbol)->toBe('USDT');

    expect(SpyWebhookHandler::$handled)->toHaveCount(2);

    $outgoing = EvmTransaction::where('txid', '0xnative-out')->first();
    expect($outgoing->type->value)->toBe('out')
        ->and($outgoing->network_id)->toBe($network->id);
});

it('is idempotent on re-run and fires no duplicate webhooks', function () {
    [$network, $address] = syncSetup();

    (new AddressNetworkSync($address, $network))->run();
    SpyWebhookHandler::$handled = [];

    (new AddressNetworkSync($address->fresh(), $network))->run();

    expect(EvmTransaction::count())->toBe(3)
        ->and(EvmDeposit::count())->toBe(2)
        ->and(SpyWebhookHandler::$handled)->toBeEmpty();
});

it('runs the full evm:sync command across active networks', function () {
    [$network] = syncSetup();

    $this->artisan('evm:sync')->assertSuccessful();

    expect(EvmTransaction::count())->toBe(3)
        ->and($network->fresh()->sync_at)->not->toBeNull();
});

it('skips inactive networks in evm:sync', function () {
    [$network] = syncSetup();
    $network->update(['active' => false]);

    $this->artisan('evm:sync')->assertSuccessful();

    expect(EvmTransaction::count())->toBe(0);
});

it('skips wallets without the network attached in evm:sync', function () {
    [$network, $address] = syncSetup();
    Evm::detachNetwork($address->wallet, $network);

    $this->artisan('evm:sync')->assertSuccessful();

    expect(EvmTransaction::count())->toBe(0);
});

function didSyncBalance(): bool
{
    $sent = false;
    Http::assertSent(function ($request) use (&$sent) {
        if (($request->data()['method'] ?? null) === 'eth_getBalance') {
            $sent = true;
        }

        return true;
    });

    return $sent;
}

it('skips an active address synced within the fast interval', function () {
    [$network, $address] = syncSetup();
    config()->set('evm.touch.enabled', true);
    config()->set('evm.touch.fast_interval', 300);

    $address->update(['touch_at' => now()]);                                   // active
    $address->balanceForNetwork($network)->update(['sync_at' => now()->subSeconds(60)]); // 60s < 300

    (new AddressNetworkSync($address->fresh(), $network))->run();

    expect(didSyncBalance())->toBeFalse();
});

it('syncs an active address once the fast interval elapses', function () {
    [$network, $address] = syncSetup();
    config()->set('evm.touch.enabled', true);
    config()->set('evm.touch.fast_interval', 300);

    $address->update(['touch_at' => now()]);                                    // active
    $address->balanceForNetwork($network)->update(['sync_at' => now()->subSeconds(600)]); // 600s > 300

    (new AddressNetworkSync($address->fresh(), $network))->run();

    expect(didSyncBalance())->toBeTrue();
});

it('syncs an idle address only after the slow interval', function () {
    [$network, $address] = syncSetup();
    config()->set('evm.touch.enabled', true);
    config()->set('evm.touch.fast_interval', 60);
    config()->set('evm.touch.slow_interval', 3600);

    // idle: touched 2h ago (beyond the 3600s active window)
    $address->update(['touch_at' => now()->subSeconds(7200)]);

    // synced 10 min ago — within slow interval → skip
    $address->balanceForNetwork($network)->update(['sync_at' => now()->subSeconds(600)]);
    (new AddressNetworkSync($address->fresh(), $network))->run();
    expect(didSyncBalance())->toBeFalse();

    // synced 2h ago — beyond slow interval → sync
    $address->balanceForNetwork($network)->update(['sync_at' => now()->subSeconds(7200)]);
    (new AddressNetworkSync($address->fresh(), $network))->run();
    expect(didSyncBalance())->toBeTrue();
});

it('skips idle addresses entirely when slow_interval is null', function () {
    [$network, $address] = syncSetup();
    config()->set('evm.touch.enabled', true);
    config()->set('evm.touch.slow_interval', null);

    $address->update(['touch_at' => now()->subSeconds(7200)]);                   // idle
    $address->balanceForNetwork($network)->update(['sync_at' => now()->subSeconds(99999)]);

    (new AddressNetworkSync($address->fresh(), $network))->run();

    expect(didSyncBalance())->toBeFalse();
});

it('attaches and detaches networks idempotently', function () {
    [$network, $address] = syncSetup();
    $wallet = $address->wallet;

    expect(Evm::hasNetwork($wallet, $network))->toBeTrue();

    Evm::attachNetwork($wallet, $network);
    expect($wallet->networks()->count())->toBe(1);

    Evm::detachNetwork($wallet, $network);
    expect(Evm::hasNetwork($wallet, $network))->toBeFalse()
        ->and($network->fresh())->not->toBeNull();
});

/* ---------- Change detection: the explorer is scanned only when something changed ---------- */

function explorerScans(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => in_array($pair[0]['action'] ?? null, ['txlist', 'tokentx'], true))
        ->count();
}

function syncAgain($address, $network, bool $force = false): void
{
    (new AddressNetworkSync($address->fresh(), $network, $force))->run();
}

/** First sync, then past the settle window, so the next scheduled sync may skip */
function settledSync($test): array
{
    [$network, $address] = syncSetup();

    (new AddressNetworkSync($address, $network))->run();
    $test->travel(11)->minutes();

    return [$network, $address];
}

it('scans the explorer on the first sync and remembers what it scanned', function () {
    [$network, $address] = syncSetup();

    (new AddressNetworkSync($address, $network))->run();

    $state = $address->balanceForNetwork($network)->fresh()->scan_state;

    expect(explorerScans())->toBe(2) // txlist + tokentx
        ->and($state['fingerprint'])->toBeString()
        ->and($state['scanned_at'])->not->toBeNull()
        ->and($state['settle_until'])->not->toBeNull();
});

it('skips the explorer when balances and nonce are unchanged', function () {
    [$network, $address] = settledSync($this);
    $before = explorerScans();
    $syncBlock = $address->balanceForNetwork($network)->fresh()->sync_block_number;

    syncAgain($address, $network);

    $row = $address->balanceForNetwork($network)->fresh();

    expect(explorerScans())->toBe($before)
        // balances still refreshed from the node
        ->and(didSyncBalance())->toBeTrue()
        ->and($row->sync_at->isToday())->toBeTrue()
        // the scan window is not moved, so nothing between the scans is lost
        ->and($row->sync_block_number)->toBe($syncBlock);
});

it('scans the explorer when the native balance changed', function () {
    [$network, $address] = settledSync($this);
    $before = explorerScans();

    SyncChainState::$balance = '0x29a2241af62c0000'; // 3 ETH
    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2);
});

it('scans the explorer when a token balance changed', function () {
    [$network, $address] = settledSync($this);
    $before = explorerScans();

    SyncChainState::$usdt = 100_000_000;
    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2);
});

it('scans the explorer when the nonce changed (something was sent)', function () {
    [$network, $address] = settledSync($this);
    $before = explorerScans();

    SyncChainState::$nonce = 2;
    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2);
});

it('scans the explorer when a token is activated', function () {
    [$network, $address] = settledSync($this);
    $before = explorerScans();

    $network->tokens()->create([
        'address' => '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359',
        'name' => 'USD Coin',
        'symbol' => 'USDC',
        'decimals' => 6,
    ]);
    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2);
});

it('keeps scanning during the settle window so a transfer indexed late is still found', function () {
    [$network, $address] = settledSync($this);
    EvmTransaction::query()->delete();
    EvmDeposit::query()->delete();
    SpyWebhookHandler::$handled = [];

    // the node already shows the new balance, the explorer has not indexed the transfer yet
    SyncChainState::$balance = '0x29a2241af62c0000';
    SyncChainState::$indexed = false;
    syncAgain($address, $network);

    expect(EvmDeposit::count())->toBe(0);

    // a minute later — fingerprint unchanged, but still within the settle window
    $this->travel(1)->minutes();
    SyncChainState::$indexed = true;
    syncAgain($address, $network);

    expect(EvmDeposit::count())->toBe(2)
        ->and(SpyWebhookHandler::$handled)->toHaveCount(2);
});

it('stops scanning once the settle window is over', function () {
    [$network, $address] = settledSync($this);

    SyncChainState::$balance = '0x29a2241af62c0000';
    syncAgain($address, $network);           // change → scan, settle window opens

    $this->travel(5)->minutes();
    $before = explorerScans();
    syncAgain($address, $network);           // within the window → scan
    expect(explorerScans())->toBe($before + 2);

    $this->travel(6)->minutes();             // 11 minutes after the change
    $before = explorerScans();
    syncAgain($address, $network);           // window closed, nothing changed → skip
    expect(explorerScans())->toBe($before);
});

it('does not extend the settle window while nothing changes', function () {
    [$network, $address] = settledSync($this);

    SyncChainState::$balance = '0x29a2241af62c0000';
    syncAgain($address, $network);
    $settleUntil = $address->balanceForNetwork($network)->fresh()->scan_state['settle_until'];

    $this->travel(3)->minutes();
    syncAgain($address, $network);

    expect($address->balanceForNetwork($network)->fresh()->scan_state['settle_until'])->toBe($settleUntil);
});

it('scans the explorer at least every max_skip_seconds as a safety net', function () {
    config()->set('evm.sync.change_detection.max_skip_seconds', 3600);
    [$network, $address] = settledSync($this);

    $this->travel(30)->minutes();
    $before = explorerScans();
    syncAgain($address, $network);
    expect(explorerScans())->toBe($before);

    $this->travel(31)->minutes();            // over an hour since the last scan
    syncAgain($address, $network);
    expect(explorerScans())->toBe($before + 2);
});

it('always scans the explorer on a forced sync (webhook, manual resync)', function () {
    [$network, $address] = settledSync($this);
    $before = explorerScans();

    syncAgain($address, $network, force: true);

    expect(explorerScans())->toBe($before + 2);
});

it('always scans the explorer on a full rescan from block zero', function () {
    [$network, $address] = settledSync($this);
    $address->balanceForNetwork($network)->update(['sync_block_number' => 0]);
    $before = explorerScans();

    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2);
});

it('scans again on the next sync when the explorer scan failed', function () {
    [$network, $address] = syncSetup();
    SyncChainState::$explorerDown = true;

    expect(fn () => (new AddressNetworkSync($address, $network))->run())->toThrow(Exception::class);
    expect($address->balanceForNetwork($network)->fresh()->scan_state)->toBeNull();

    SyncChainState::$explorerDown = false;
    $before = explorerScans();
    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2)
        ->and(EvmDeposit::count())->toBe(2);
});

it('always scans the explorer when change detection is disabled', function () {
    config()->set('evm.sync.change_detection.enabled', false);
    [$network, $address] = settledSync($this);
    $before = explorerScans();

    syncAgain($address, $network);

    expect(explorerScans())->toBe($before + 2);
    Http::assertNotSent(fn ($request) => ($request->data()['method'] ?? null) === 'eth_getTransactionCount');
});

it('still resolves pending outgoing transfers when the explorer is skipped', function () {
    [$network, $address] = settledSync($this);

    EvmTransaction::create([
        'network_id' => $network->id,
        'txid' => '0xpending',
        'address' => $address->address,
        'type' => 'out',
        'time_at' => now(),
        'from' => $address->address,
        'to' => '0x2222222222222222222222222222222222222222',
        'amount' => '0.1',
        'token_address' => '',
        'nonce' => 0, // below the confirmed nonce 1 → settled
    ]);

    $before = explorerScans();
    syncAgain($address, $network);

    expect(explorerScans())->toBe($before)
        ->and(EvmTransaction::where('txid', '0xpending')->first()->block_number)->toBe(500);
});

function internalTransfer(array $overrides = []): array
{
    return [
        'hash' => '0xbridge',
        'blockNumber' => '130',
        'timeStamp' => '1700002000',
        'from' => '0x394767c0000000000000000000000000000bc5f6',
        'to' => ADDR,
        'value' => '3000000000000000000', // 3
        'contractAddress' => '',
        'type' => 'call',
        'traceId' => '0_1',
        'isError' => '0',
        ...$overrides,
    ];
}

it('records an internal transfer (a bridge) as an incoming transaction and deposit', function () {
    [$network, $address] = syncSetup();
    SyncChainState::$internal = [internalTransfer()];

    (new AddressNetworkSync($address, $network))->run();

    $row = EvmTransaction::where('txid', '0xbridge')->sole();
    $deposit = EvmDeposit::where('txid', '0xbridge')->sole();

    expect($row->internal)->toBeTrue()
        ->and($row->type->value)->toBe('in')
        ->and($row->from)->toBe('0x394767c0000000000000000000000000000bc5f6')
        ->and($row->amount->isEqualTo('3'))->toBeTrue()
        ->and($row->block_number)->toBe(130)
        ->and($deposit->token_id)->toBeNull()
        ->and($deposit->amount->isEqualTo('3'))->toBeTrue()
        ->and($deposit->confirmations)->toBe(512 - 130)
        ->and(collect(SpyWebhookHandler::$handled)->pluck('txid'))->toContain('0xbridge')
        ->and(EvmTransaction::where('internal', false)->count())->toBe(3);
});

it('sums several internal transfers of one transaction and skips failed, empty and creation calls', function () {
    [$network, $address] = syncSetup();
    SyncChainState::$internal = [
        internalTransfer(['value' => '1000000000000000000', 'traceId' => '0_1']),
        internalTransfer(['value' => '500000000000000000', 'traceId' => '0_2']),
        internalTransfer(['hash' => '0xfailed', 'isError' => '1']),
        internalTransfer(['hash' => '0xempty', 'value' => '0']),
        internalTransfer(['hash' => '0xcreate', 'to' => '', 'contractAddress' => '0x5555555555555555555555555555555555555555']),
    ];

    (new AddressNetworkSync($address, $network))->run();

    expect(EvmTransaction::where('internal', true)->pluck('txid')->all())->toBe(['0xbridge'])
        ->and(EvmTransaction::where('txid', '0xbridge')->sole()->amount->isEqualTo('1.5'))->toBeTrue()
        ->and(EvmDeposit::where('txid', '0xbridge')->sole()->amount->isEqualTo('1.5'))->toBeTrue();
});

it('keeps the outgoing call and the coin a swap returned in the same transaction', function () {
    [$network, $address] = syncSetup();
    // 0xnative-out is our outgoing call; the contract sends 0.2 back to us inside it
    SyncChainState::$internal = [internalTransfer(['hash' => '0xnative-out', 'blockNumber' => '110', 'value' => '200000000000000000'])];

    (new AddressNetworkSync($address, $network))->run();

    $rows = EvmTransaction::where('txid', '0xnative-out')->orderBy('internal')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->internal)->toBeFalse()
        ->and($rows[0]->type->value)->toBe('out')
        ->and($rows[1]->internal)->toBeTrue()
        ->and($rows[1]->type->value)->toBe('in')
        ->and($rows[1]->amount->isEqualTo('0.2'))->toBeTrue();
});

it('does not duplicate internal transfers or their webhooks on the next scan', function () {
    [$network, $address] = syncSetup();
    SyncChainState::$internal = [internalTransfer()];

    (new AddressNetworkSync($address, $network))->run();
    SpyWebhookHandler::$handled = [];
    (new AddressNetworkSync($address->fresh(), $network, true))->run();

    expect(EvmTransaction::where('internal', true)->count())->toBe(1)
        ->and(EvmDeposit::where('txid', '0xbridge')->count())->toBe(1)
        ->and(SpyWebhookHandler::$handled)->toBeEmpty();
});

it('writes nothing and retries the range when the internal transfers request fails', function () {
    [$network, $address] = syncSetup();
    SyncChainState::$internalDown = true;

    expect(fn () => (new AddressNetworkSync($address, $network))->run())->toThrow(\Exception::class);

    expect(EvmTransaction::count())->toBe(0)
        ->and(EvmDeposit::count())->toBe(0)
        ->and(SpyWebhookHandler::$handled)->toBeEmpty()
        ->and($address->balanceForNetwork($network)->fresh()->sync_block_number)->toBeNull();

    SyncChainState::$internalDown = false;
    SyncChainState::$internal = [internalTransfer()];
    (new AddressNetworkSync($address->fresh(), $network))->run();

    expect(EvmTransaction::count())->toBe(4)
        ->and(SpyWebhookHandler::$handled)->toHaveCount(3);
});

it('does not ask for internal transfers when they are disabled', function () {
    [$network, $address] = syncSetup();
    config()->set('evm.sync.internal_transfers.enabled', false);
    SyncChainState::$internal = [internalTransfer()];

    (new AddressNetworkSync($address, $network))->run();

    expect(EvmTransaction::where('internal', true)->count())->toBe(0);
    Http::assertNotSent(fn ($request) => ($request['action'] ?? null) === 'txlistinternal');
});

/**
 * A network Alchemy does not index internal transfers on (BSC): Alchemy is the main explorer,
 * Etherscan is the second one.
 */
function alchemyBscSetup(bool $withEtherscan): array
{
    fakeSyncApis();
    Http::fake([
        'bsc-mainnet.g.alchemy.com/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['transfers' => []]]),
    ]);
    SyncChainState::reset();
    SpyWebhookHandler::$handled = [];
    config()->set('evm.webhook_handler', SpyWebhookHandler::class);

    $network = Evm::createNetwork(56, 'bsc', 'BNB');
    Evm::createNode($network, 'node', 'https://rpc.test');
    $alchemy = Evm::createExplorer($network, ExplorerDriver::Alchemy, 'alchemy', baseURL: 'https://bsc-mainnet.g.alchemy.com/v2/KEY');
    $etherscan = $withEtherscan ? Evm::createExplorer($network, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'KEY') : null;
    // the main explorer is the least used one: make it Alchemy
    $etherscan?->update(['requests' => 1000]);

    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');
    Evm::attachNetwork($wallet, $network);

    return [$network, $wallet->addresses->first(), $alchemy, $etherscan];
}

it('asks another explorer of the network for internal transfers Alchemy does not index there', function () {
    [$network, $address, $alchemy, $etherscan] = alchemyBscSetup(withEtherscan: true);
    SyncChainState::$internal = [internalTransfer()];

    (new AddressNetworkSync($address, $network))->run();

    expect(EvmTransaction::where('internal', true)->sole()->txid)->toBe('0xbridge')
        ->and($etherscan->fresh()->requests)->toBe(1001);

    Http::assertNotSent(fn ($request) => in_array('internal', $request->data()['params'][0]['category'] ?? [], true));
    Http::assertNotSent(fn ($request) => in_array($request['action'] ?? null, ['txlist', 'tokentx'], true));
});

it('still syncs an Alchemy network without any source of internal transfers', function () {
    [$network, $address] = alchemyBscSetup(withEtherscan: false);
    $messages = [];

    (new AddressNetworkSync($address, $network))
        ->setLogger(function (string $message, ?string $type) use (&$messages) {
            $messages[] = [$type, $message];
        })
        ->run();

    expect($address->balanceForNetwork($network)->fresh()->sync_block_number)->toBe(512 - 20)
        ->and(collect($messages)->where(0, 'warn')->pluck(1)->implode(' '))->toContain('internal transfers');
});

it('backfills internal transfers from block zero without webhooks and without moving the cursor', function () {
    [$network, $address] = syncSetup();
    (new AddressNetworkSync($address, $network))->run();
    $cursor = $address->balanceForNetwork($network)->fresh()->sync_block_number;
    SpyWebhookHandler::$handled = [];
    SyncChainState::$internal = [internalTransfer(['blockNumber' => '5'])];

    $this->artisan('evm:sync-internal')->assertSuccessful();

    expect(EvmTransaction::where('internal', true)->sole()->block_number)->toBe(5)
        ->and(EvmDeposit::where('txid', '0xbridge')->count())->toBe(1)
        ->and(SpyWebhookHandler::$handled)->toBeEmpty()
        ->and($address->balanceForNetwork($network)->fresh()->sync_block_number)->toBe($cursor);

    Http::assertSent(fn ($request) => ($request['action'] ?? null) === 'txlistinternal' && (string)($request['startblock'] ?? '') === '0');

    // Repeating changes nothing
    $this->artisan('evm:sync-internal')->assertSuccessful();
    expect(EvmTransaction::where('internal', true)->count())->toBe(1);
});

it('calls the webhook handler for backfilled deposits only with --webhooks', function () {
    [$network, $address] = syncSetup();
    SyncChainState::$internal = [internalTransfer()];

    $this->artisan('evm:sync-internal', ['--webhooks' => true, '--network' => 'polygon'])->assertSuccessful();

    expect(collect(SpyWebhookHandler::$handled)->pluck('txid')->all())->toBe(['0xbridge']);
});
