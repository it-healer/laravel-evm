<?php

namespace ItHealer\LaravelEvm\Tx;

interface TransactionSignerInterface
{
    /**
     * Builds and signs a raw transaction.
     *
     * All hex arguments are strings without the 0x prefix; empty string means zero.
     * The $fees array depends on the transaction type:
     *  - legacy:   ['gas_price' => hex]
     *  - EIP-1559: ['max_fee_per_gas' => hex, 'max_priority_fee_per_gas' => hex]
     *
     * @return string signed raw transaction, 0x-prefixed
     */
    public function sign(
        int $chainId,
        int $nonce,
        string $to,
        string $valueHex,
        string $dataHex,
        string $gasLimitHex,
        array $fees,
        string $privateKey,
    ): string;
}
