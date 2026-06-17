<?php

namespace ItHealer\LaravelEvm\Api\Node\DTO;

class TransferDTO extends PreviewTransferDTO
{
    public function txid(): string
    {
        return $this->getOrFail('txid');
    }

    /**
     * The account nonce that was allocated and signed for this transaction.
     * Used to reconcile stuck/replaced pending transfers against the
     * confirmed chain nonce (a pending transfer whose nonce is already below
     * the confirmed nonce was dropped or replaced).
     */
    public function nonce(): ?int
    {
        $value = $this->get('nonce');

        return $value !== null ? (int) $value : null;
    }
}
