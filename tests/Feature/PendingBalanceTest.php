<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Enums\TransactionType;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmTransaction;
use ItHealer\LaravelEvm\Services\PendingBalance;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;

uses(RefreshDatabase::class);

const PB_USDT = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';

/**
 * @param  array<string, mixed>  $rpc  extra/overriding RPC method responses
 */
function pendingSetup(array $rpc = []): array
{
    Http::fake([
        'rpc.test*' => function ($request) use ($rpc) {
            $data = $request->data();
            $method = $data['method'];

            $defaults = [
                'eth_blockNumber' => '0x200',
                'eth_getBalance' => '0x1bc16d674ec80000', // 2 ETH
                'eth_getTransactionCount' => '0x0',
                'eth_call' => match (substr($data['params'][0]['data'] ?? '', 0, 10)) {
                    '0x313ce567' => '0x'.str_pad('6', 64, '0', STR_PAD_LEFT),
                    default => '0x'.str_pad(dechex(75_000_000), 64, '0', STR_PAD_LEFT), // 75 USDT
                },
            ];

            $responses = [...$defaults, ...$rpc];
            $result = $responses[$method] ?? null;

            // null result is valid (e.g. eth_getTransactionReceipt of a dropped tx)
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        },
        'api.etherscan.io/*' => fn () => Http::response(['status' => '1', 'message' => 'OK', 'result' => []]),
    ]);

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    Evm::createNode($network, 'node', 'https://rpc.test');
    Evm::createExplorer($network, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'KEY');
    $network->tokens()->create([
        'address' => PB_USDT,
        'name' => 'Tether USD',
        'symbol' => 'USDT',
        'decimals' => 6,
    ]);

    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');
    Evm::attachNetwork($wallet, $network);

    $address = $wallet->addresses->first();

    $address->balanceForNetwork($network)->update([
        'balance' => '2',
        'tokens' => [PB_USDT => '75.000000'],
        'sync_at' => now(),
    ]);

    return [$network, $address];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makePending(int $networkId, string $address, array $attributes): EvmTransaction
{
    return EvmTransaction::create([
        'network_id' => $networkId,
        'txid' => $attributes['txid'],
        'address' => $address,
        'type' => $attributes['type'] ?? TransactionType::OUTGOING,
        'time_at' => $attributes['time_at'] ?? now(),
        'from' => $address,
        'to' => '0x2222222222222222222222222222222222222222',
        'amount' => $attributes['amount'],
        'fee' => $attributes['fee'] ?? null,
        'token_address' => $attributes['token_address'] ?? '',
        'block_number' => $attributes['block_number'] ?? null,
        'nonce' => $attributes['nonce'] ?? null,
        'dropped_at' => $attributes['dropped_at'] ?? null,
        'data' => [],
    ]);
}

it('subtracts pending outgoing amount and fee from the available native balance', function () {
    [$network, $address] = pendingSetup();

    makePending($network->id, $address->address, ['txid' => '0xa', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 7]);

    $pending = PendingBalance::forAddress($network->id, $address->address);

    expect($pending['native']->isEqualTo('0.5'))->toBeTrue()
        ->and($pending['fee']->isEqualTo('0.01'))->toBeTrue();

    $balanceRow = $address->balanceForNetwork($network)->fresh();

    // 2 - 0.5 - 0.01 = 1.49
    expect((string) $balanceRow->available_balance)->toBe('1.49')
        ->and((float) (string) $balanceRow->balance)->toBe(2.0);
});

it('subtracts pending outgoing token transfers from the available token balance', function () {
    [$network, $address] = pendingSetup();

    makePending($network->id, $address->address, [
        'txid' => '0xb',
        'amount' => '25',
        'fee' => '0.02',
        'token_address' => PB_USDT,
        'nonce' => 8,
    ]);

    $balanceRow = $address->balanceForNetwork($network)->fresh();

    // native available reduced only by the fee: 2 - 0 - 0.02 = 1.98
    expect((string) $balanceRow->available_balance)->toBe('1.98');

    // token available: 75 - 25 = 50
    $usdt = collect($balanceRow->available_tokens_balances)->firstWhere('address', PB_USDT);
    expect((float) $usdt['balance'])->toBe(50.0);
});

it('ignores confirmed and dropped transfers when computing pending', function () {
    [$network, $address] = pendingSetup();

    makePending($network->id, $address->address, ['txid' => '0xconfirmed', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 1, 'block_number' => 100]);
    makePending($network->id, $address->address, ['txid' => '0xdropped', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 2, 'dropped_at' => now()]);
    makePending($network->id, $address->address, ['txid' => '0xincoming', 'amount' => '0.5', 'type' => TransactionType::INCOMING, 'nonce' => 3]);

    $pending = PendingBalance::forAddress($network->id, $address->address);

    expect($pending['native']->isZero())->toBeTrue()
        ->and($pending['fee']->isZero())->toBeTrue();

    expect((string) $address->balanceForNetwork($network)->fresh()->available_balance)->toBe('2');
});

it('reconciles a pending transfer that has been mined by stamping its block number', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x6', // confirmed nonce 6 > pending nonce 5
        'eth_getTransactionReceipt' => ['blockNumber' => '0x90'], // 144
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xmined', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->block_number)->toBe(144)
        ->and($tx->fresh()->dropped_at)->toBeNull();
});

it('reconciles a stuck/replaced pending transfer by marking it dropped', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x6', // confirmed nonce 6 > pending nonce 5
        'eth_getTransactionReceipt' => null, // never mined
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xreplaced', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->dropped_at)->not->toBeNull()
        ->and($tx->fresh()->block_number)->toBeNull();

    // available balance is back to the confirmed on-chain balance
    expect((string) $address->balanceForNetwork($network)->fresh()->available_balance)->toBe('2');
});

it('keeps subtracting a pending transfer whose nonce is still unconfirmed', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x5', // confirmed nonce 5 == pending nonce 5 → not yet passed
        'eth_getTransactionByHash' => ['hash' => '0xinflight', 'blockNumber' => null], // still in mempool
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xinflight', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->dropped_at)->toBeNull()
        ->and($tx->fresh()->block_number)->toBeNull();
});

it('drops a still-next pending transfer the node no longer knows after the grace', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x5', // confirmed nonce 5 == pending nonce 5 → still next
        'eth_getTransactionByHash' => null, // node has never seen it → evicted from the mempool
    ]);

    $tx = makePending($network->id, $address->address, [
        'txid' => '0xevicted',
        'amount' => '0.5',
        'fee' => '0.01',
        'nonce' => 5,
        'time_at' => now()->subMinutes(10), // older than the dropped grace
    ]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->dropped_at)->not->toBeNull()
        ->and($tx->fresh()->block_number)->toBeNull();

    expect((string) $address->balanceForNetwork($network)->fresh()->available_balance)->toBe('2');
});

it('keeps a still-next pending transfer the node no longer knows within the grace', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x5',
        'eth_getTransactionByHash' => null, // not seen yet — but just broadcast
    ]);

    $tx = makePending($network->id, $address->address, [
        'txid' => '0xfresh',
        'amount' => '0.5',
        'fee' => '0.01',
        'nonce' => 5,
        'time_at' => now(), // within the dropped grace → do not drop prematurely
    ]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->dropped_at)->toBeNull()
        ->and($tx->fresh()->block_number)->toBeNull();
});

it('drops a legacy pending transfer with no nonce the node no longer knows', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionByHash' => null, // node has never seen it
    ]);

    $tx = makePending($network->id, $address->address, [
        'txid' => '0xlegacy',
        'amount' => '0.5',
        'fee' => '0.01',
        // no nonce — legacy row created before nonce tracking
        'time_at' => now()->subMonths(2),
    ]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->dropped_at)->not->toBeNull()
        ->and($tx->fresh()->block_number)->toBeNull();
});

it('stamps a legacy pending transfer with no nonce found mined', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionByHash' => ['hash' => '0xlegacymined', 'blockNumber' => '0x90'],
        'eth_getTransactionReceipt' => ['blockNumber' => '0x90', 'status' => '0x1'],
    ]);

    $tx = makePending($network->id, $address->address, [
        'txid' => '0xlegacymined',
        'amount' => '0.5',
        'fee' => '0.01',
        'time_at' => now()->subMonths(2),
    ]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->block_number)->toBe(144)
        ->and($tx->fresh()->dropped_at)->toBeNull();
});

it('flags a pending transfer mined but reverted (nonce passed)', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x6', // confirmed nonce 6 > pending nonce 5
        'eth_getTransactionReceipt' => ['blockNumber' => '0x90', 'status' => '0x0'], // mined, reverted
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xreverted', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->block_number)->toBe(144)
        ->and($tx->fresh()->failed)->toBeTrue()
        ->and($tx->fresh()->dropped_at)->toBeNull();
});

it('flags a still-next pending transfer mined but reverted via getTransactionByHash', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x5', // confirmed nonce 5 == pending nonce 5
        'eth_getTransactionByHash' => ['hash' => '0xrev2', 'blockNumber' => '0x90'],
        'eth_getTransactionReceipt' => ['blockNumber' => '0x90', 'status' => '0x0'],
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xrev2', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->block_number)->toBe(144)
        ->and($tx->fresh()->failed)->toBeTrue();
});

it('does not flag a successfully mined pending transfer as failed', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x6',
        'eth_getTransactionReceipt' => ['blockNumber' => '0x90', 'status' => '0x1'], // success
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xok', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->block_number)->toBe(144)
        ->and($tx->fresh()->failed)->toBeFalse();
});

it('stamps a still-next pending transfer found mined via getTransactionByHash', function () {
    [$network, $address] = pendingSetup([
        'eth_getTransactionCount' => '0x5', // confirmed nonce 5 == pending nonce 5
        'eth_getTransactionByHash' => ['hash' => '0xmined2', 'blockNumber' => '0x90'], // 144
    ]);

    $tx = makePending($network->id, $address->address, ['txid' => '0xmined2', 'amount' => '0.5', 'fee' => '0.01', 'nonce' => 5]);

    (new AddressNetworkSync($address, $network))->run();

    expect($tx->fresh()->block_number)->toBe(144)
        ->and($tx->fresh()->dropped_at)->toBeNull();
});
