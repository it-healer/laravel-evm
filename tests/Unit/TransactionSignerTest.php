<?php

use Brick\Math\BigInteger;
use kornrunner\Keccak;
use ItHealer\LaravelEvm\Tx\Eip1559TransactionSigner;
use ItHealer\LaravelEvm\Tx\LegacyTransactionSigner;
use ItHealer\LaravelEvm\Tx\Support\Hex;

function vectors(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/vectors.json'), true);
}

function decToHex(string $decimal): string
{
    $value = BigInteger::of($decimal);

    return $value->isZero() ? '' : Hex::fromBigInteger($value);
}

function txHash(string $raw): string
{
    return '0x'.Keccak::hash(hex2bin(Hex::strip0x($raw)), 256);
}

it('signs legacy transactions matching ethers.js vectors', function (int $i) {
    $vectors = vectors();
    $vector = $vectors['legacy'][$i];
    $tx = $vector['tx'];

    $raw = (new LegacyTransactionSigner())->sign(
        chainId: (int)$tx['chainId'],
        nonce: (int)$tx['nonce'],
        to: $tx['to'],
        valueHex: decToHex($tx['value']),
        dataHex: $tx['data'],
        gasLimitHex: decToHex($tx['gasLimit']),
        fees: ['gas_price' => decToHex($tx['gasPrice'])],
        privateKey: $vectors['signer_private_key'],
    );

    expect($raw)->toBe($vector['raw'])
        ->and(txHash($raw))->toBe($vector['hash']);
})->with([0, 1, 2]);

it('signs EIP-1559 transactions matching ethers.js vectors', function (int $i) {
    $vectors = vectors();
    $vector = $vectors['eip1559'][$i];
    $tx = $vector['tx'];

    $raw = (new Eip1559TransactionSigner())->sign(
        chainId: (int)$tx['chainId'],
        nonce: (int)$tx['nonce'],
        to: $tx['to'],
        valueHex: decToHex($tx['value']),
        dataHex: $tx['data'],
        gasLimitHex: decToHex($tx['gasLimit']),
        fees: [
            'max_fee_per_gas' => decToHex($tx['maxFeePerGas']),
            'max_priority_fee_per_gas' => decToHex($tx['maxPriorityFeePerGas']),
        ],
        privateKey: $vectors['signer_private_key'],
    );

    expect($raw)->toBe($vector['raw'])
        ->and(txHash($raw))->toBe($vector['hash']);
})->with([0, 1, 2]);

it('matches the canonical EIP-155 example raw transaction', function () {
    $raw = (new LegacyTransactionSigner())->sign(
        chainId: 1,
        nonce: 9,
        to: '0x3535353535353535353535353535353535353535',
        valueHex: 'de0b6b3a7640000',
        dataHex: '',
        gasLimitHex: '5208',
        fees: ['gas_price' => '04a817c800'],
        privateKey: '4646464646464646464646464646464646464646464646464646464646464646',
    );

    expect($raw)->toBe(
        '0xf86c098504a817c800825208943535353535353535353535353535353535353535880'
        .'de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e'
        .'1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83'
    );
});
