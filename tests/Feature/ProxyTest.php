<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Explorer\ExplorerManager;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Services\ChainList\ChainListService;
use ItHealer\LaravelEvm\Support\ProxyFormatter;

uses(RefreshDatabase::class);

function readProxyProperty(object $object): ?string
{
    $reflection = new \ReflectionProperty($object, 'proxy');

    return $reflection->getValue($object);
}

it('formats proxies', function (string $input, string $expected) {
    expect(ProxyFormatter::format($input))->toBe($expected);
})->with([
    'plain http' => ['http://1.2.3.4:8080', 'http://1.2.3.4:8080'],
    'socks5 with auth' => ['socks5://user:pass@proxy.local:1080', 'socks5://user:pass@proxy.local:1080'],
    'no port' => ['https://proxy.local', 'https://proxy.local'],
]);

it('returns null for an empty proxy', function () {
    expect(ProxyFormatter::format(null))->toBeNull()
        ->and(ProxyFormatter::format(''))->toBeNull();
});

it('rejects an invalid proxy format', function () {
    ProxyFormatter::format('proxy.local:8080');
})->throws(InvalidArgumentException::class);

function fakeNodeRpc(): void
{
    Http::fake([
        'rpc.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1']),
    ]);
}

it('uses the config proxy as a fallback for nodes', function () {
    config(['evm.proxy' => 'socks5://1.2.3.4:1080']);
    fakeNodeRpc();

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    $node = Evm::createNode($network, 'main', 'https://rpc.test');

    expect(readProxyProperty($node->api()))->toBe('socks5://1.2.3.4:1080');
});

it('prefers the node own proxy over the config proxy', function () {
    config(['evm.proxy' => 'socks5://1.2.3.4:1080']);
    fakeNodeRpc();

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    $node = Evm::createNode($network, 'main', 'https://rpc.test', proxy: 'http://5.6.7.8:3128');

    expect(readProxyProperty($node->api()))->toBe('http://5.6.7.8:3128');
});

it('uses the config proxy as a fallback for explorers', function () {
    config(['evm.proxy' => 'http://1.2.3.4:8080']);
    Http::fake([
        'api.etherscan.io/*' => Http::response(['status' => '1', 'message' => 'OK', 'result' => []]),
    ]);

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    $explorer = Evm::createExplorer($network, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'key');

    expect(readProxyProperty(ExplorerManager::make($explorer)))->toBe('http://1.2.3.4:8080');
});

it('fetches the chain list through the config proxy', function () {
    config(['evm.proxy' => 'http://1.2.3.4:8080']);

    Http::fake([
        'chainid.network/*' => Http::response([
            [
                'name' => 'Polygon Mainnet',
                'chainId' => 137,
                'shortName' => 'pol',
                'nativeCurrency' => ['name' => 'POL', 'symbol' => 'POL', 'decimals' => 18],
                'rpc' => ['https://polygon-rpc.com'],
            ],
        ]),
    ]);

    expect(app(ChainListService::class)->all())->toHaveCount(1);
});
