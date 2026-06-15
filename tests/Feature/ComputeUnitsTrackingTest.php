<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;

uses(RefreshDatabase::class);

const TOKEN = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';

function fakeCreditApis(): void
{
    Http::fake([
        'rpc.test*' => function ($request) {
            $result = match ($request->data()['method']) {
                'eth_blockNumber' => '0x200',
                'eth_getBalance' => '0x1bc16d674ec80000',
                'eth_call' => '0x'.str_pad(dechex(75_000_000), 64, '0', STR_PAD_LEFT),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        },
        'alchemy.test*' => function ($request) {
            if ($request->data()['method'] === 'eth_blockNumber') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x200']);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['transfers' => []]]);
        },
    ]);
}

function creditSetup(): array
{
    fakeCreditApis();

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    Evm::createNode($network, 'node', 'https://rpc.test');
    Evm::createExplorer($network, ExplorerDriver::Alchemy, 'alchemy', baseURL: 'https://alchemy.test/v2/KEY');
    $network->tokens()->create([
        'address' => TOKEN,
        'name' => 'Tether USD',
        'symbol' => 'USDT',
        'decimals' => 6,
    ]);

    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');
    Evm::attachNetwork($wallet, $network);

    return [$network, $wallet->addresses->first()];
}

it('meters node and explorer compute units during a sync', function () {
    [$network, $address] = creditSetup();

    (new AddressNetworkSync($address, $network))->run();

    // node: eth_blockNumber (10) + eth_getBalance (19) + eth_call balanceOf (26) = 55
    expect(Evm::getNode($network)->credits)->toBe(55)
        // explorer: getAssetTransfers x4 (to/from native, to/from token) x 150 = 600
        ->and(Evm::getExplorer($network)->credits)->toBe(600);
});

it('does not spend a decimals() eth_call when the token decimals are known', function () {
    [$network, $address] = creditSetup();

    (new AddressNetworkSync($address, $network))->run();

    Http::assertNotSent(fn ($request) => ($request->data()['method'] ?? null) === 'eth_call'
        && str_starts_with($request->data()['params'][0]['data'], '0x313ce567'));
});

it('halves explorer compute units when outgoing tracking is disabled', function () {
    config()->set('evm.sync.track_outgoing', false);

    [$network, $address] = creditSetup();

    (new AddressNetworkSync($address, $network))->run();

    // only incoming direction: getAssetTransfers x2 (native + token) x 150 = 300
    expect(Evm::getExplorer($network)->credits)->toBe(300);

    $transferCalls = 0;
    Http::assertSent(function ($request) use (&$transferCalls) {
        if (($request->data()['method'] ?? null) === 'alchemy_getAssetTransfers') {
            $transferCalls++;
        }

        return true;
    });

    expect($transferCalls)->toBe(2);
});

it('resets the credits counter at the start of a new month', function () {
    [$network] = creditSetup();
    $node = Evm::getNode($network);

    $node->forceFill(['credits' => 999, 'credits_at' => now()->subMonths(2)])->save();
    $node->recordCredits(10);
    expect($node->fresh()->credits)->toBe(10);

    $node->fresh()->recordCredits(5);
    expect($node->fresh()->credits)->toBe(15);
});

it('selects the node with the fewest credits this month', function () {
    [$network] = creditSetup();
    Evm::createNode($network, 'node-2', 'https://rpc.test');

    $nodes = $network->nodes()->orderBy('id')->get();
    $nodes[0]->forceFill(['credits' => 5000, 'credits_at' => now()])->save();
    $nodes[1]->forceFill(['credits' => 10, 'credits_at' => now()])->save();

    expect(Evm::getNode($network)->name)->toBe('node-2');

    // a stale-month counter ranks as zero, so it wins again
    $nodes[0]->forceFill(['credits' => 5000, 'credits_at' => now()->subMonths(2)])->save();
    expect(Evm::getNode($network)->name)->toBe('node');
});
