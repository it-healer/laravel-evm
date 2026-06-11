<?php

use ItHealer\LaravelEvm\Evm;

beforeEach(function () {
    $this->evm = new Evm();
    $this->vectors = json_decode(file_get_contents(__DIR__.'/../Fixtures/vectors.json'), true);
});

it('computes BIP-39 seed from mnemonic', function () {
    expect($this->evm->mnemonicSeed($this->vectors['mnemonic']))
        ->toBe($this->vectors['seed']);
});

it('validates mnemonic phrases', function () {
    expect($this->evm->mnemonicValidate($this->vectors['mnemonic']))->toBeTrue()
        ->and($this->evm->mnemonicValidate('not a valid mnemonic phrase at all'))->toBeFalse();
});

it('generates mnemonic of requested size', function () {
    $words = $this->evm->mnemonicGenerate(18);

    expect($words)->toHaveCount(18)
        ->and($this->evm->mnemonicValidate($words))->toBeTrue();
});

it('derives addresses and private keys by path', function (string $family) {
    foreach ($this->vectors['derivation'][$family] as $vector) {
        $derived = $this->evm->deriveFromSeed($this->vectors['seed'], $vector['path']);

        expect($derived['address'])->toBe($vector['address'], "path {$vector['path']}")
            ->and($derived['private_key'])->toBe($vector['private_key'], "path {$vector['path']}");
    }
})->with(['bip44', 'ledger_live', 'ledger_legacy']);

it('resolves derivation path templates', function () {
    expect($this->evm->resolveDerivationPath("m/44'/60'/0'/0/{index}", 5))->toBe("m/44'/60'/0'/0/5")
        ->and($this->evm->resolveDerivationPath("m/44'/60'/{index}'/0/0", 2))->toBe("m/44'/60'/2'/0/0")
        ->and($this->evm->resolveDerivationPath("m/44'/60'/0'/0", 0))->toBe("m/44'/60'/0'/0");
});

it('rejects index > 0 for templates without placeholder', function () {
    $this->evm->resolveDerivationPath("m/44'/60'/0'/0", 1);
})->throws(InvalidArgumentException::class);

it('rejects invalid derivation paths', function (string $template) {
    $this->evm->resolveDerivationPath($template, 0);
})->with([
    'no m prefix' => "44'/60'/0'/0/{index}",
    'garbage' => 'hello world',
    'empty' => '',
    'double slash' => "m//44'/60'",
])->throws(InvalidArgumentException::class);
