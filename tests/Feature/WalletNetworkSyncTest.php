<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Api\Node\Multicall3;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmAddressBalance;
use ItHealer\LaravelEvm\Services\Sync\WalletNetworkSync;

uses(RefreshDatabase::class);

const WNS_TOKEN = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';

/** What the fake node does and what it was asked */
class WalletSyncNode
{
    public static bool $multicallDeployed = true;

    /** @var list<string> */
    public static array $methods = [];

    /** @var list<int> sub-calls of every Multicall3 request */
    public static array $multicallSizes = [];

    public static function reset(): void
    {
        self::$multicallDeployed = true;
        self::$methods = [];
        self::$multicallSizes = [];
    }

    /** native balance of an address: 1 + its last byte (in whole coins, 18 decimals) */
    public static function nativeWei(string $address): string
    {
        return bcmul((string)(1 + hexdec(substr($address, -2))), bcpow('10', '18'));
    }

    /** token balance: 10 + last byte (6 decimals) */
    public static function tokenUnits(string $address): string
    {
        return (string)((10 + hexdec(substr($address, -2))) * 1_000_000);
    }
}

function wnsWord(string $hex): string
{
    return str_pad($hex, 64, '0', STR_PAD_LEFT);
}

function wnsDecBig(string $decimal): string
{
    return \Brick\Math\BigInteger::of($decimal)->toBase(16);
}

/**
 * ABI encoding of a Multicall3 `(bool success, bytes returnData)[]` answer.
 *
 * @param  list<array{0: bool, 1: string}>  $results
 */
function wnsEncodeResults(array $results): string
{
    $heads = [];
    $bodies = [];
    $offset = 32 * count($results);

    foreach ($results as [$success, $data]) {
        $length = intdiv(strlen($data), 2);
        $body = wnsWord($success ? '1' : '0').wnsWord(dechex(64)).wnsWord(dechex($length)).str_pad($data, (int)ceil($length / 32) * 64, '0');
        $heads[] = wnsWord(dechex($offset));
        $bodies[] = $body;
        $offset += intdiv(strlen($body), 2);
    }

    return '0x'.wnsWord(dechex(32)).wnsWord(dechex(count($results))).implode('', $heads).implode('', $bodies);
}

/**
 * Decodes the aggregate3 request the sync sent, to answer every sub-call.
 *
 * @return list<array{target: string, data: string}>
 */
function wnsDecodeCalls(string $hex): array
{
    $data = substr($hex, 10);
    $read = fn (int $byte) => hexdec(substr($data, $byte * 2, 64));
    $count = $read(32);
    $calls = [];

    for ($i = 0; $i < $count; $i++) {
        $at = 64 + $read(64 + 32 * $i);
        $length = $read($at + 96);
        $calls[] = [
            'target' => '0x'.substr($data, ($at + 12) * 2, 40),
            'data' => substr($data, ($at + 128) * 2, $length * 2),
        ];
    }

    return $calls;
}

function wnsFakeApis(): void
{
    Http::fake([
        'rpc.test*' => function ($request) {
            $data = $request->data();
            WalletSyncNode::$methods[] = $data['method'];

            $result = match ($data['method']) {
                'eth_blockNumber' => '0x200',
                'eth_getCode' => WalletSyncNode::$multicallDeployed ? '0x6080604052' : '0x',
                'eth_getBalance' => '0x'.wnsDecBig(WalletSyncNode::nativeWei($data['params'][0])),
                'eth_getTransactionCount' => '0x1',
                'eth_call' => (function () use ($data) {
                    $call = $data['params'][0];

                    if (strtolower($call['to']) === strtolower(Multicall3::ADDRESS) && str_starts_with($call['data'], '0x'.Multicall3::AGGREGATE3)) {
                        $calls = wnsDecodeCalls($call['data']);
                        WalletSyncNode::$multicallSizes[] = count($calls);

                        return wnsEncodeResults(array_map(function (array $sub) {
                            $address = '0x'.substr($sub['data'], 32, 40);

                            return [true, wnsWord(wnsDecBig(str_starts_with($sub['data'], Multicall3::GET_ETH_BALANCE)
                                ? WalletSyncNode::nativeWei($address)
                                : WalletSyncNode::tokenUnits($address)))];
                        }, $calls));
                    }

                    // a direct balanceOf
                    return '0x'.wnsWord(wnsDecBig(WalletSyncNode::tokenUnits('0x'.substr($call['data'], -40))));
                })(),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        },
        'api.etherscan.io/*' => fn () => Http::response(['status' => '0', 'message' => 'No transactions found', 'result' => []]),
    ]);
}

/**
 * Two networks attached to one wallet of three addresses.
 */
function wnsSetup(): array
{
    WalletSyncNode::reset();
    Cache::flush();
    wnsFakeApis();

    config()->set('evm.touch.enabled', true);
    config()->set('evm.touch.per_network', true);
    config()->set('evm.touch.waiting_seconds', 1800);
    config()->set('evm.touch.fast_interval', 60);
    config()->set('evm.touch.slow_interval', 3600);

    $polygon = Evm::createNetwork(137, 'polygon', 'POL');
    Evm::createNode($polygon, 'node', 'https://rpc.test');
    Evm::createExplorer($polygon, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'KEY');
    $polygon->tokens()->create(['address' => WNS_TOKEN, 'name' => 'Tether USD', 'symbol' => 'USDT', 'decimals' => 6]);

    $optimism = Evm::createNetwork(10, 'optimism', 'ETH');
    Evm::createNode($optimism, 'node-op', 'https://rpc.test/op');
    Evm::createExplorer($optimism, ExplorerDriver::EtherscanV2, 'etherscan-op', apiKey: 'KEY');

    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');
    Evm::createAddress($wallet);
    Evm::createAddress($wallet);
    Evm::attachNetwork($wallet, $polygon);
    Evm::attachNetwork($wallet, $optimism);

    return [$wallet->fresh(), $polygon, $optimism];
}

function wnsRun($wallet, $network): void
{
    (new WalletNetworkSync($wallet, $network))->run();
}

it('reads the balances of all addresses and tokens of a wallet in one Multicall3 request', function () {
    [$wallet, $polygon] = wnsSetup();
    $wallet->touchNetwork($polygon);

    wnsRun($wallet, $polygon);

    // 3 addresses × (native + 1 token) = 6 sub-calls in one eth_call, no eth_getBalance
    expect(WalletSyncNode::$multicallSizes)->toBe([6])
        ->and(WalletSyncNode::$methods)->not->toContain('eth_getBalance');

    foreach ($wallet->addresses as $address) {
        $row = $address->balanceForNetwork($polygon);
        $last = hexdec(substr(strtolower($address->address), -2));

        expect($row->balance->isEqualTo(1 + $last))->toBeTrue()
            ->and(\Brick\Math\BigDecimal::of($row->tokens[WNS_TOKEN])->isEqualTo(10 + $last))->toBeTrue();
    }
});

it('asks the balances one by one on a network without Multicall3', function () {
    [$wallet, $polygon] = wnsSetup();
    WalletSyncNode::$multicallDeployed = false;
    $wallet->touchNetwork($polygon);

    wnsRun($wallet, $polygon);

    expect(WalletSyncNode::$multicallSizes)->toBe([])
        ->and(collect(WalletSyncNode::$methods)->filter(fn ($m) => $m === 'eth_getBalance')->count())->toBe(3);

    $address = $wallet->addresses->first();
    $last = hexdec(substr(strtolower($address->address), -2));

    expect(\Brick\Math\BigDecimal::of($address->balanceForNetwork($polygon)->tokens[WNS_TOKEN])->isEqualTo(10 + $last))->toBeTrue();
});

it('keeps a network idle when only another network of the wallet is used', function () {
    [$wallet, $polygon, $optimism] = wnsSetup();

    // Both synced once, an hour ago; now the user works in Polygon only
    foreach ($wallet->addresses as $address) {
        foreach ([$polygon, $optimism] as $network) {
            $address->balanceForNetwork($network)->update(['sync_at' => now()->subMinutes(5), 'touch_at' => now()->subHours(2)]);
        }
    }

    $wallet->touchNetwork($polygon);

    WalletSyncNode::$methods = [];
    wnsRun($wallet, $optimism);

    // Optimism: idle (slow interval not reached) — not a single request, not even eth_blockNumber
    expect(WalletSyncNode::$methods)->toBe([]);

    wnsRun($wallet, $polygon);

    expect(WalletSyncNode::$multicallSizes)->toBe([6]);
});

it('creates missing balance rows when a network is touched, but not for a never-synced hidden reserve', function () {
    [$wallet, $polygon, $optimism] = wnsSetup();
    $reserve = Evm::createAddress($wallet);
    $reserve->update(['available' => false, 'sync_at' => null]);

    $wallet->touchNetwork($optimism);

    expect(EvmAddressBalance::query()->where('network_id', $optimism->id)->whereNotNull('touch_at')->count())->toBe(3)
        ->and(EvmAddressBalance::query()->where('address_id', $reserve->id)->exists())->toBeFalse()
        ->and($reserve->fresh()->touch_at)->toBeNull()
        ->and(EvmAddressBalance::query()->where('network_id', $polygon->id)->count())->toBe(0);
});

it('falls back to the address touch when per-network touch is off', function () {
    [$wallet, $polygon, $optimism] = wnsSetup();
    config()->set('evm.touch.per_network', false);

    foreach ($wallet->addresses as $address) {
        $address->balanceForNetwork($optimism)->update(['sync_at' => now()->subMinutes(5)]);
    }

    // The legacy touch of the address keeps every network active
    $wallet->addresses()->update(['touch_at' => now()]);

    wnsRun($wallet, $optimism);

    expect(WalletSyncNode::$methods)->toContain('eth_call');
});
