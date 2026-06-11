<?php

namespace ItHealer\LaravelEvm\Api\Node\DTO;

class TransferDTO extends PreviewTransferDTO
{
    public function txid(): string
    {
        return $this->getOrFail('txid');
    }
}
