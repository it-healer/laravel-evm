<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Services\ChainList\ChainListService;
use ItHealer\LaravelEvm\Services\TokenList\TokenListService;

uses(RefreshDatabase::class);

function fakeChainList(): void
{
    Http::fake([
        'chainid.network/*' => Http::response([
            [
                'name' => 'Ethereum Mainnet',
                'chainId' => 1,
                'shortName' => 'eth',
                'nativeCurrency' => ['name' => 'Ether', 'symbol' => 'ETH', 'decimals' => 18],
                'rpc' => [
                    'https://mainnet.infura.io/v3/${INFURA_API_KEY}',
                    'wss://mainnet.gateway.tenderly.co',
                    'https://eth.llamarpc.com',
                ],
                'explorers' => [['name' => 'etherscan', 'url' => 'https://etherscan.io', 'standard' => 'EIP3091']],
                'infoURL' => 'https://ethereum.org',
            ],
            [
                'name' => 'Polygon Mainnet',
                'chainId' => 137,
                'shortName' => 'pol',
                'nativeCurrency' => ['name' => 'POL', 'symbol' => 'POL', 'decimals' => 18],
                'rpc' => ['https://polygon-rpc.com'],
                'explorers' => [['name' => 'polygonscan', 'url' => 'https://polygonscan.com', 'standard' => 'EIP3091']],
            ],
        ]),
    ]);
}

it('fetches, caches and parses the chain list', function () {
    fakeChainList();

    $service = app(ChainListService::class);

    expect($service->all())->toHaveCount(2);
    $service->all();

    Http::assertSentCount(1); // second call served from cache

    $eth = $service->find(1);

    expect($eth->currencySymbol())->toBe('ETH')
        ->and($eth->currencyDecimals())->toBe(18)
        ->and($eth->rpcUrls())->toBe(['https://eth.llamarpc.com']) // placeholder + wss filtered
        ->and($eth->explorers()[0]['url'])->toBe('https://etherscan.io')
        ->and($service->find(999))->toBeNull();
});

it('searches chains by name and symbol', function () {
    fakeChainList();

    $service = app(ChainListService::class);

    expect($service->search('polygon'))->toHaveCount(1)
        ->and($service->search('eth')->first()->chainId())->toBe(1);
});

it('creates a network from the chain list', function () {
    fakeChainList();

    $network = Evm::createNetworkFromChainList(137);

    expect($network->chain_id)->toBe(137)
        ->and($network->name)->toBe('pol')
        ->and($network->title)->toBe('Polygon Mainnet')
        ->and($network->currency_symbol)->toBe('POL')
        ->and($network->explorer_url)->toBe('https://polygonscan.com');
});

it('fetches token lists and filters by chain', function () {
    Http::fake([
        'tokens.uniswap.org' => Http::response([
            'name' => 'Uniswap Labs Default',
            'tokens' => [
                [
                    'chainId' => 1,
                    'address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                    'name' => 'Tether USD',
                    'symbol' => 'USDT',
                    'decimals' => 6,
                    'logoURI' => 'https://example.com/usdt.png',
                ],
                [
                    'chainId' => 137,
                    'address' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                    'name' => 'Tether USD',
                    'symbol' => 'USDT',
                    'decimals' => 6,
                ],
            ],
        ]),
    ]);

    $tokens = app(TokenListService::class)->forChain(137);

    expect($tokens)->toHaveCount(1)
        ->and($tokens->first()->address())->toBe('0xc2132d05d31c914a87c6611c10748aeb04b58e8f')
        ->and($tokens->first()->symbol())->toBe('USDT');
});

it('creates a token from token list metadata without RPC', function () {
    Http::fake([
        'tokens.uniswap.org' => Http::response([
            'tokens' => [[
                'chainId' => 137,
                'address' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                'name' => 'Tether USD',
                'symbol' => 'USDT',
                'decimals' => 6,
                'logoURI' => 'https://example.com/usdt.png',
            ]],
        ]),
    ]);

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    $info = app(TokenListService::class)->forChain(137)->first();
    $token = Evm::createTokenFromList($network, $info);

    expect($token->address)->toBe('0xc2132d05d31c914a87c6611c10748aeb04b58e8f')
        ->and($token->decimals)->toBe(6)
        ->and($token->logo_uri)->toBe('https://example.com/usdt.png')
        ->and($token->network_id)->toBe($network->id);
});

it('rejects token from a different chain', function () {
    Http::fake([
        'tokens.uniswap.org' => Http::response([
            'tokens' => [[
                'chainId' => 1,
                'address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                'name' => 'Tether USD',
                'symbol' => 'USDT',
                'decimals' => 6,
            ]],
        ]),
    ]);

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    $info = app(TokenListService::class)->fetch('https://tokens.uniswap.org')->first();

    Evm::createTokenFromList($network, $info);
})->throws(InvalidArgumentException::class);
