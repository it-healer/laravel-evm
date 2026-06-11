<?php

namespace ItHealer\LaravelEvm\Api\Node\DTO;

use Brick\Math\BigDecimal;
use ItHealer\LaravelEvm\Api\BaseDTO;
use ItHealer\LaravelEvm\Enums\TxType;

class FeeEstimateDTO extends BaseDTO
{
    public function txType(): TxType
    {
        return TxType::from($this->getOrFail('tx_type'));
    }

    /**
     * Price per gas unit (in wei) used for the worst-case fee calculation:
     * gasPrice for legacy, maxFeePerGas for EIP-1559.
     */
    public function effectiveGasPrice(): BigDecimal
    {
        return BigDecimal::of(
            $this->txType() === TxType::Legacy
                ? $this->getOrFail('gas_price')
                : $this->getOrFail('max_fee_per_gas')
        );
    }

    public function gasPrice(): ?BigDecimal
    {
        $value = $this->get('gas_price');

        return $value !== null ? BigDecimal::of($value) : null;
    }

    public function baseFee(): ?BigDecimal
    {
        $value = $this->get('base_fee');

        return $value !== null ? BigDecimal::of($value) : null;
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
}
