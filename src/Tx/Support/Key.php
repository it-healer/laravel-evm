<?php

namespace ItHealer\LaravelEvm\Tx\Support;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;

class Key
{
    /**
     * Derives the (lowercase, non-checksummed) Ethereum address from a private key.
     */
    public static function privateKeyToAddress(string $privateKey): string
    {
        $privateKey = Hex::strip0x($privateKey);

        if (!preg_match('/^[a-fA-F0-9]{64}$/', $privateKey)) {
            throw new InvalidArgumentException('Private key must be a 64-character hexadecimal string.');
        }

        $publicKey = (new EC('secp256k1'))
            ->keyFromPrivate($privateKey, 'hex')
            ->getPublic(false, 'hex');

        $hash = Keccak::hash(hex2bin(substr($publicKey, 2)), 256);

        return '0x'.substr($hash, -40);
    }
}
