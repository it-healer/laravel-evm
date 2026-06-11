<?php

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Models\EvmTransaction;
use ItHealer\LaravelEvm\Models\EvmWallet;

uses(RefreshDatabase::class);

function makeNetwork(array $attributes = []): EvmNetwork
{
    return EvmNetwork::create([
        'chain_id' => 137,
        'name' => 'polygon',
        'title' => 'Polygon Mainnet',
        'currency_symbol' => 'POL',
        ...$attributes,
    ]);
}

it('creates a network with nodes and tokens', function () {
    $network = makeNetwork();

    $node = $network->nodes()->create([
        'name' => 'polygon-rpc',
        'base_url' => 'https://polygon-rpc.com',
    ]);

    $token = $network->tokens()->create([
        'address' => '0xc2132d05d31c914a87c6611c10748aeb04b58e8f',
        'name' => 'Tether USD',
        'symbol' => 'USDT',
        'decimals' => 6,
    ]);

    expect($network->effectiveLagBlocks())->toBe(20)
        ->and($node->network->id)->toBe($network->id)
        ->and($token->network->name)->toBe('polygon')
        ->and(EvmNode::count())->toBe(1);
});

it('stores wallet secrets encrypted and keeps derivation path', function () {
    $wallet = EvmWallet::create([
        'name' => 'main',
        'derivation_path' => "m/44'/60'/{index}'/0/0",
    ]);
    $wallet->mnemonic = 'test test test test test test test test test test test junk';
    $wallet->seed = 'aabbcc';
    $wallet->save();

    $raw = $wallet->getRawOriginal('mnemonic');

    expect($raw)->not->toContain('test test')
        ->and($wallet->fresh()->mnemonic)->toBe('test test test test test test test test test test test junk')
        ->and($wallet->derivation_path)->toBe("m/44'/60'/{index}'/0/0")
        ->and($wallet->has_mnemonic)->toBeTrue()
        ->and($wallet->makeHidden('mnemonic')->toArray())->not->toHaveKeys(['mnemonic', 'seed']);
});

it('tracks per-network balances of one address', function () {
    $polygon = makeNetwork();
    $bsc = makeNetwork(['chain_id' => 56, 'name' => 'bsc', 'currency_symbol' => 'BNB']);

    $wallet = EvmWallet::create(['name' => 'w']);
    $address = $wallet->addresses()->create(['address' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266']);

    $address->balanceForNetwork($polygon)->update([
        'balance' => '1.5',
        'tokens' => ['0xc2132d05d31c914a87c6611c10748aeb04b58e8f' => '100'],
        'sync_block_number' => 1000,
    ]);
    $address->balanceForNetwork($bsc)->update(['balance' => '0.25']);

    expect($address->balances)->toHaveCount(2)
        ->and($address->balanceForNetwork($polygon)->balance->isEqualTo('1.5'))->toBeTrue()
        ->and($wallet->balanceForNetwork($bsc)->isEqualTo('0.25'))->toBeTrue()
        ->and($wallet->tokensForNetwork($polygon))->toHaveKey('0xc2132d05d31c914a87c6611c10748aeb04b58e8f');
});

it('enforces uniqueness of transactions per network/txid/address/token', function () {
    $network = makeNetwork();

    $attributes = [
        'network_id' => $network->id,
        'txid' => '0xabc',
        'address' => '0xdef',
        'type' => 'in',
        'time_at' => now(),
        'from' => '0x1',
        'to' => '0xdef',
        'amount' => '1',
    ];

    EvmTransaction::create($attributes);
    EvmTransaction::create([...$attributes, 'token_address' => '0xtoken']);

    expect(EvmTransaction::count())->toBe(2);

    EvmTransaction::create($attributes);
})->throws(Illuminate\Database\QueryException::class);

it('resolves deposit symbol from network or token', function () {
    $network = makeNetwork();
    $wallet = EvmWallet::create(['name' => 'w']);
    $address = $wallet->addresses()->create(['address' => '0xabc']);
    $token = $network->tokens()->create([
        'address' => '0xc2132d05d31c914a87c6611c10748aeb04b58e8f',
        'name' => 'Tether USD',
        'symbol' => 'USDT',
        'decimals' => 6,
    ]);

    $native = EvmDeposit::create([
        'network_id' => $network->id,
        'wallet_id' => $wallet->id,
        'address_id' => $address->id,
        'txid' => '0x1',
        'amount' => '1.23',
        'time_at' => now(),
    ]);

    $erc20 = EvmDeposit::create([
        'network_id' => $network->id,
        'wallet_id' => $wallet->id,
        'address_id' => $address->id,
        'token_id' => $token->id,
        'txid' => '0x2',
        'amount' => '50',
        'time_at' => now(),
    ]);

    expect($native->symbol)->toBe('POL')
        ->and($erc20->symbol)->toBe('USDT')
        ->and($erc20->amount)->toBeInstanceOf(BigDecimal::class);
});
