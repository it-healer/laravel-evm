<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Exceptions\AlchemyNetworkNotFoundException;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Services\Alchemy\AlchemySlugFinder;
use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;

uses(RefreshDatabase::class);

/**
 * chainid.network знает сеть 777777 как «Newchain Network» (shortName nwc); Alchemy отвечает её
 * chain id только по адресу newchain-mainnet, а сосед с чужой сетью — другим chain id.
 */
function fakeNewchain(string $answeringSlug = 'newchain-mainnet', int $answeredChainId = 777777): void
{
    Http::fake([
        'chainid.network/*' => Http::response([[
            'name' => 'Newchain Network', 'chainId' => 777777, 'shortName' => 'nwc',
            'nativeCurrency' => ['name' => 'Ether', 'symbol' => 'ETH', 'decimals' => 18], 'rpc' => [], 'explorers' => [],
        ]]),
        "{$answeringSlug}.g.alchemy.com/*" => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.dechex($answeredChainId)]),
        // мимо: эта сеть Alchemy — другая
        'newchain.g.alchemy.com/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1']),
        '*.g.alchemy.com/*' => Http::response('Not Found', 404),
    ]);
}

it('guesses the slugs from the network names', function () {
    fakeNewchain();
    $network = Evm::createNetwork(777777, 'newchain', 'ETH');

    $candidates = app(AlchemySlugFinder::class)->candidates($network);

    expect($candidates)->toContain('newchain-mainnet', 'newchain-network-mainnet', 'nwc-mainnet')
        ->and(count($candidates))->toBeLessThanOrEqual(AlchemySlugFinder::MAX_CANDIDATES);
});

it('finds the slug that answers with the chain id of the network and remembers it', function () {
    fakeNewchain();
    $network = Evm::createNetwork(777777, 'newchain', 'ETH');

    expect(app(AlchemySlugFinder::class)->find($network, 'KEY'))->toBe('newchain-mainnet')
        ->and($network->fresh()->alchemy_slug)->toBe('newchain-mainnet')
        ->and(AlchemyUrlFactory::makeFor($network->fresh(), 'KEY'))->toBe('https://newchain-mainnet.g.alchemy.com/v2/KEY');
});

it('does not take a slug of another network', function () {
    fakeNewchain(answeredChainId: 1);
    $network = Evm::createNetwork(777777, 'newchain', 'ETH');

    expect(app(AlchemySlugFinder::class)->find($network, 'KEY'))->toBeNull()
        ->and($network->fresh()->alchemy_slug)->toBeNull();
});

it('uses the slug the admin entered and the built-in list without asking Alchemy', function () {
    Http::fake();
    $polygon = Evm::createNetwork(137, 'polygon', 'POL');
    $custom = Evm::createNetwork(888888, 'custom', 'ETH');
    $custom->update(['alchemy_slug' => 'custom-mainnet']);

    expect(app(AlchemySlugFinder::class)->find($polygon, 'KEY'))->toBe('polygon-mainnet')
        ->and(app(AlchemySlugFinder::class)->find($custom, 'KEY'))->toBe('custom-mainnet');

    Http::assertNothingSent();
});

it('creates an Alchemy node of a network found by the finder and explains when it is not found', function () {
    // newchain-mainnet отвечает 0xbde31 (777777) на любой запрос — и на eth_blockNumber при проверке ноды
    fakeNewchain();
    $network = Evm::createNetwork(777777, 'newchain', 'ETH');

    $node = Evm::createAlchemyNode($network, 'KEY', 'alchemy');

    expect($node->base_url)->toBe('https://newchain-mainnet.g.alchemy.com/v2/KEY');

    $unknown = Evm::createNetwork(999999, 'unknown', 'ETH');

    expect(fn () => Evm::createAlchemyNode($unknown, 'KEY', 'alchemy'))->toThrow(AlchemyNetworkNotFoundException::class);
});
