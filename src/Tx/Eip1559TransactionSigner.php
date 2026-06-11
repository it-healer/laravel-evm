<?php

namespace ItHealer\LaravelEvm\Tx;

use kornrunner\Ethereum\EIP1559Transaction;
use ItHealer\LaravelEvm\Tx\Support\Hex;

class Eip1559TransactionSigner implements TransactionSignerInterface
{
    public function sign(
        int $chainId,
        int $nonce,
        string $to,
        string $valueHex,
        string $dataHex,
        string $gasLimitHex,
        array $fees,
        string $privateKey,
    ): string {
        $transaction = new EIP1559Transaction(
            nonce: $nonce > 0 ? Hex::fromInt($nonce) : '',
            maxPriorityFeePerGas: $fees['max_priority_fee_per_gas'],
            maxFeePerGas: $fees['max_fee_per_gas'],
            gasLimit: $gasLimitHex,
            to: strtolower(Hex::strip0x($to)),
            value: strtolower(Hex::strip0x($valueHex)),
            data: strtolower(Hex::strip0x($dataHex)),
        );

        return '0x'.$transaction->getRaw($privateKey, $chainId);
    }
}
