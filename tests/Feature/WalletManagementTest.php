<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use ItHealer\LaravelEvm\Evm as EvmClass;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmWallet;

uses(RefreshDatabase::class);

const TEST_MNEMONIC = 'test test test test test test test test test test test junk';

it('creates a wallet with a primary address using the default path', function () {
    $wallet = Evm::createWallet('main', mnemonic: TEST_MNEMONIC);

    expect($wallet->derivation_path)->toBe(EvmClass::PATH_BIP44)
        ->and($wallet->addresses)->toHaveCount(1)
        ->and($wallet->addresses->first()->address)->toBe('0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266')
        ->and($wallet->addresses->first()->index)->toBe(0);
});

it('creates a wallet with a custom derivation path (Ledger Live)', function () {
    $wallet = Evm::createWallet(
        'ledger',
        mnemonic: TEST_MNEMONIC,
        derivationPath: EvmClass::PATH_LEDGER_LIVE,
    );

    $second = Evm::createAddress($wallet);

    expect($wallet->addresses()->orderBy('index')->pluck('address')->all())->toBe([
        '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
        '0x8C8d35429F74ec245F8Ef2f4Fd1e551cFF97d650',
    ])->and($second->index)->toBe(1);
});

it('rejects an invalid derivation path template', function () {
    Evm::createWallet('bad', mnemonic: TEST_MNEMONIC, derivationPath: 'not-a-path');
})->throws(InvalidArgumentException::class);

it('encrypts the private key with the wallet password', function () {
    $wallet = Evm::createWallet('secure', password: 'secret', mnemonic: TEST_MNEMONIC);
    $address = $wallet->addresses->first();

    expect($address->private_key)
        ->toBe('ac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');

    $fresh = EvmWallet::firstWhere('name', 'secure');
    $fresh->unlockWallet('secret');

    expect($fresh->addresses->first()->private_key)
        ->toBe('ac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
});

it('imports a watch-only address', function () {
    $wallet = Evm::newWallet('watch');
    $wallet->save();

    $address = Evm::importAddress($wallet, '0x52908400098527886E0F7030069857D2E4169EE7');

    expect($address->watch_only)->toBeTrue()
        ->and($address->private_key)->toBeNull();
});

it('creates a network from facade', function () {
    $network = Evm::createNetwork(56, 'bsc', 'BNB', title: 'BNB Smart Chain', lagBlocks: 60);

    expect(Evm::findNetwork(56)->id)->toBe($network->id)
        ->and(Evm::findNetwork('bsc')->id)->toBe($network->id)
        ->and($network->effectiveLagBlocks())->toBe(60);
});
