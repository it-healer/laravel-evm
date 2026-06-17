<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Enums\TxType;
use ItHealer\LaravelEvm\Exceptions\NetworkInactiveException;
use ItHealer\LaravelEvm\Exceptions\TransferException;
use ItHealer\LaravelEvm\Facades\Evm;

uses(RefreshDatabase::class);

const RPC_URL = 'rpc.test*';

function fakeRpc(array $overrides = []): void
{
    $responses = [
        'eth_blockNumber' => '0x100',
        'eth_getBlockByNumber' => ['number' => '0x100', 'baseFeePerGas' => '0x3b9aca00'], // 1 gwei
        'eth_gasPrice' => '0x77359400', // 2 gwei
        'eth_maxPriorityFeePerGas' => '0x5f5e100', // 0.1 gwei
        'eth_estimateGas' => '0x5208', // 21000
        'eth_getBalance' => '0xde0b6b3a7640000', // 1 ether
        'eth_getTransactionCount' => '0x5',
        'eth_sendRawTransaction' => '0xtxid123',
        ...$overrides,
    ];

    Http::fake([
        RPC_URL => function ($request) use ($responses) {
            $method = $request->data()['method'];

            if (!array_key_exists($method, $responses)) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => "method {$method} not faked"]]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $responses[$method]]);
        },
    ]);
}

function setupNetwork(array $attributes = []): array
{
    fakeRpc();

    $network = Evm::createNetwork(11155111, 'sepolia', 'ETH', ...$attributes);
    $node = Evm::createNode($network, 'test-node', 'https://rpc.test');
    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');

    return [$network, $node, $wallet->addresses->first()];
}

it('sends a native transfer as EIP-1559 when base fee is present', function () {
    [$network, , $from] = setupNetwork();

    $result = Evm::transfer($network, $from, '0x3535353535353535353535353535353535353535', '0.5');

    expect($result->txid())->toBe('0xtxid123')
        ->and($result->txType())->toBe(TxType::Eip1559)
        ->and($result->maxFeePerGas()->isEqualTo('2100000000'))->toBeTrue() // 2 * baseFee + priority
        ->and($result->fee()->isEqualTo('0.0000441'))->toBeTrue() // maxFee * 21000 / 1e18
        ->and($result->nonce())->toBe(5); // eth_getTransactionCount(pending) = 0x5

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $data['method'] === 'eth_sendRawTransaction'
            && str_starts_with($data['params'][0], '0x02'); // type-2 envelope
    });
});

it('encodes the estimateGas value as a canonical quantity without leading zeros', function () {
    [$network, , $from] = setupNetwork();

    // 1 ETH = 1e18 wei = 0xde0b6b3a7640000 (odd nibble count) — must not be padded to 0x0de0...
    Evm::previewTransfer($network, $from, '0x3535353535353535353535353535353535353535', '1');

    Http::assertSent(function ($request) {
        $data = $request->data();

        if ($data['method'] !== 'eth_estimateGas') {
            return false;
        }

        return ($data['params'][0]['value'] ?? null) === '0xde0b6b3a7640000';
    });
});

it('sends a legacy transfer when tx_type is forced to 0', function () {
    [$network, , $from] = setupNetwork(['txType' => TxType::Legacy]);

    $result = Evm::transfer($network, $from, '0x3535353535353535353535353535353535353535', '0.5');

    expect($result->txType())->toBe(TxType::Legacy)
        ->and($result->gasPrice()->isEqualTo('2000000000'))->toBeTrue();

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $data['method'] === 'eth_sendRawTransaction'
            && str_starts_with($data['params'][0], '0xf8'); // legacy RLP list
    });
});

it('auto-detects legacy when block has no base fee', function () {
    fakeRpc(['eth_getBlockByNumber' => ['number' => '0x100']]);

    $network = Evm::createNetwork(56, 'bsc', 'BNB');
    Evm::createNode($network, 'bsc-node', 'https://rpc.test');
    $wallet = Evm::createWallet('w2', mnemonic: 'test test test test test test test test test test test junk');

    $preview = Evm::previewTransfer($network, $wallet->addresses->first(), '0x3535353535353535353535353535353535353535', '0.1');

    expect($preview->txType())->toBe(TxType::Legacy);
});

it('reserves the next nonce per network and address', function () {
    [$network, , $from] = setupNetwork();

    Evm::transfer($network, $from, '0x3535353535353535353535353535353535353535', '0.1');

    $key = 'evm:next-nonce:11155111:'.strtolower($from->address);

    expect(Cache::get($key))->toBe(6); // chain nonce 5 + 1
});

it('fails the transfer when balance is insufficient', function () {
    [$network, , $from] = setupNetwork();

    Evm::transfer($network, $from, '0x3535353535353535353535353535353535353535', '5');
})->throws(TransferException::class, 'Insufficient native balance');

it('refuses transfers on inactive networks', function () {
    [$network, , $from] = setupNetwork();
    $network->update(['active' => false]);

    Evm::transfer($network->fresh(), $from, '0x3535353535353535353535353535353535353535', '0.1');
})->throws(NetworkInactiveException::class);

it('sends an ERC-20 token transfer with correct calldata', function () {
    $ethCallResponses = [
        '0x313ce567' => '0x'.str_pad('6', 64, '0', STR_PAD_LEFT),
        '0x70a08231' => '0x'.str_pad(dechex(250_000_000), 64, '0', STR_PAD_LEFT), // 250 USDT
    ];

    Http::fake([
        RPC_URL => function ($request) use ($ethCallResponses) {
            $data = $request->data();
            $method = $data['method'];

            $responses = [
                'eth_blockNumber' => '0x100',
                'eth_getBlockByNumber' => ['number' => '0x100', 'baseFeePerGas' => '0x3b9aca00'],
                'eth_maxPriorityFeePerGas' => '0x5f5e100',
                'eth_estimateGas' => '0xfde8', // 65000
                'eth_getBalance' => '0xde0b6b3a7640000',
                'eth_getTransactionCount' => '0x0',
                'eth_sendRawTransaction' => '0xtokentx',
            ];

            if ($method === 'eth_call') {
                $selector = substr($data['params'][0]['data'], 0, 10);

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $ethCallResponses[$selector]]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $responses[$method]]);
        },
    ]);

    $network = Evm::createNetwork(11155111, 'sepolia', 'ETH');
    Evm::createNode($network, 'test-node', 'https://rpc.test');
    $wallet = Evm::createWallet('w3', mnemonic: 'test test test test test test test test test test test junk');
    $from = $wallet->addresses->first();

    $contract = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';
    $to = '0xde709f2102306220921060314715629080e2fb77';

    $result = Evm::transferToken($network, $contract, $from, $to, '100');

    expect($result->txid())->toBe('0xtokentx')
        ->and($result->data())->toBe(
            '0xa9059cbb'
            .'000000000000000000000000de709f2102306220921060314715629080e2fb77'
            .'0000000000000000000000000000000000000000000000000000000005f5e100' // 100 * 10^6
        )
        ->and($result->tokenBalanceAfter()->isEqualTo('150'))->toBeTrue();
});
