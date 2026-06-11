<?php

namespace ItHealer\LaravelEvm\Explorer\DTO;

use Brick\Math\BigDecimal;
use ItHealer\LaravelEvm\Api\BaseDTO;

class GasOracleDTO extends BaseDTO
{
    public function lastBlock(): int
    {
        return (int)$this->getOrFail('LastBlock');
    }

    public function safeGasPrice(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('SafeGasPrice'));
    }

    public function proposeGasPrice(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('ProposeGasPrice'));
    }

    public function fastGasPrice(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('FastGasPrice'));
    }
}
