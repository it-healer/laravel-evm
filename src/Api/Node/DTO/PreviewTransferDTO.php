<?php

namespace ItHealer\LaravelEvm\Api\Node\DTO;

use Brick\Math\BigDecimal;
use ItHealer\LaravelEvm\Api\BaseDTO;
use ItHealer\LaravelEvm\Enums\TxType;

class PreviewTransferDTO extends BaseDTO
{
    public function isToken(): bool
    {
        return !!$this->get('contract');
    }

    public function contract(): string
    {
        return $this->getOrFail('contract');
    }

    public function from(): string
    {
        return $this->getOrFail('from');
    }

    public function to(): string
    {
        return $this->getOrFail('to');
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('amount'));
    }

    public function data(): string
    {
        return $this->getOrFail('data');
    }

    public function txType(): TxType
    {
        return TxType::from($this->getOrFail('tx_type'));
    }

    /**
     * Price per gas unit (in wei) used for the fee calculation:
     * gasPrice for legacy, maxFeePerGas for EIP-1559 (worst case).
     */
    public function gasPrice(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('gas_price'));
    }

    public function maxFeePerGas(): ?BigDecimal
    {
        $value = $this->get('max_fee_per_gas');

        return $value !== null ? BigDecimal::of($value) : null;
    }

    public function maxPriorityFeePerGas(): ?BigDecimal
    {
        $value = $this->get('max_priority_fee_per_gas');

        return $value !== null ? BigDecimal::of($value) : null;
    }

    public function gasLimit(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('gas_limit'));
    }

    public function fee(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('fee'));
    }

    public function balanceBefore(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('balance_before'));
    }

    public function balanceAfter(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('balance_after'));
    }

    public function tokenBalanceBefore(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('token_balance_before'));
    }

    public function tokenBalanceAfter(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('token_balance_after'));
    }

    public function error(): ?string
    {
        return $this->getOrFail('error');
    }

    public function hasError(): bool
    {
        return $this->get('error') !== null;
    }
}
