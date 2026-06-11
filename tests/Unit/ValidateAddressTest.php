<?php

use ItHealer\LaravelEvm\Evm;

beforeEach(function () {
    $this->evm = new Evm();
});

// Official EIP-55 test cases
$checksummed = [
    '0x52908400098527886E0F7030069857D2E4169EE7',
    '0x8617E340B3D01FA5F11F306F4090FD50E238070D',
    '0xde709f2102306220921060314715629080e2fb77',
    '0x27b1fdb04752bbc536007a920d24acb045561c26',
    '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
    '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
    '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB',
    '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb',
];

it('accepts valid EIP-55 checksummed addresses', function (string $address) {
    expect($this->evm->validateAddress($address))->toBeTrue();
})->with($checksummed);

it('accepts all-lowercase and all-uppercase addresses', function () {
    expect($this->evm->validateAddress('0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed'))->toBeTrue()
        ->and($this->evm->validateAddress('0x5AAEB6053F3E94C9B9A09F33669435E7EF1BEAED'))->toBeTrue();
});

it('rejects addresses with broken checksum', function () {
    expect($this->evm->validateAddress('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAeD'))->toBeFalse();
});

it('rejects malformed addresses', function (string $address) {
    expect($this->evm->validateAddress($address))->toBeFalse();
})->with([
    'no prefix' => '5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
    'too short' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAe',
    'too long' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed1',
    'non hex' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAeg',
    'empty' => '',
]);

it('converts to EIP-55 checksum address', function (string $address) {
    expect($this->evm->toChecksumAddress(strtolower($address)))->toBe($address);
})->with($checksummed);

it('derives address from private key', function () {
    $vectors = json_decode(file_get_contents(__DIR__.'/../Fixtures/vectors.json'), true);

    expect($this->evm->privateKeyToAddress($vectors['signer_private_key']))
        ->toBe($vectors['signer_address']);
});
