<?php

use ItHealer\LaravelEvm\Evm;
use ItHealer\LaravelEvm\Enums\EvmModel;

it('registers the Evm singleton', function () {
    expect(app(Evm::class))->toBeInstanceOf(Evm::class)
        ->and(app(Evm::class))->toBe(app(Evm::class));
});

it('loads the package config', function () {
    expect(config('evm.sync.lag_blocks'))->toBe(20)
        ->and(config('evm.wallet.default_derivation_path'))->toBe("m/44'/60'/0'/0/{index}");
});

it('resolves model classes from config', function () {
    expect(app(Evm::class)->getModel(EvmModel::Wallet))
        ->toBe(\ItHealer\LaravelEvm\Models\EvmWallet::class);
});
