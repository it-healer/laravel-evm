<?php

namespace ItHealer\LaravelEvm\Explorer\DTO;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelEvm\Api\BaseDTO;

/**
 * Driver-agnostic native coin transaction. Drivers normalize their raw
 * payloads into these attributes:
 *  hash, block_number, timestamp (unix), from, to, amount (decimal string),
 *  is_error (bool), confirmations (?int), contract_address (?string), raw (array)
 */
class ExplorerTransactionDTO extends BaseDTO
{
    public function hash(): string
    {
        return (string)$this->getOrFail('hash');
    }

    public function blockNumber(): int
    {
        return (int)$this->getOrFail('block_number');
    }

    public function time(): Carbon
    {
        return Date::createFromTimestampUTC($this->getOrFail('timestamp'));
    }

    public function from(): string
    {
        return strtolower($this->getOrFail('from'));
    }

    public function to(): string
    {
        return strtolower((string)$this->get('to', ''));
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of($this->getOrFail('amount'));
    }

    /**
     * Filled when the row is a contract creation (no recipient).
     */
    public function contractAddress(): ?string
    {
        $value = $this->get('contract_address');

        return $value ? strtolower($value) : null;
    }

    public function confirmations(): ?int
    {
        $value = $this->get('confirmations');

        return $value !== null ? (int)$value : null;
    }

    public function isError(): bool
    {
        return (bool)$this->get('is_error', false);
    }

    public function raw(): array
    {
        return $this->get('raw', []);
    }
}
