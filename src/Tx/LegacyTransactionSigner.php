<?php

namespace ItHealer\LaravelEvm\Tx;

use kornrunner\Ethereum\Transaction;
use ItHealer\LaravelEvm\Tx\Support\Hex;

class LegacyTransactionSigner implements TransactionSignerInterface
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
        $transaction = new Transaction(
            nonce: $nonce > 0 ? Hex::fromInt($nonce) : '',
            gasPrice: $fees['gas_price'],
            gasLimit: $gasLimitHex,
            to: strtolower(Hex::strip0x($to)),
            value: strtolower(Hex::strip0x($valueHex)),
            data: strtolower(Hex::strip0x($dataHex)),
        );

        return '0x'.$transaction->getRaw($privateKey, $chainId);
    }
}
