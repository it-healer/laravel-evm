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

    public static function reset(): void
    {
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
