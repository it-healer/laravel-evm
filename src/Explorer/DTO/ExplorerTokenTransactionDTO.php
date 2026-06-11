<?php

namespace ItHealer\LaravelEvm\Explorer\DTO;

/**
 * Driver-agnostic ERC-20 transfer. In addition to ExplorerTransactionDTO
 * attributes the drivers fill: contract_address (string, required),
 * token_decimals (?int), token_symbol (?string), token_name (?string).
 */
class ExplorerTokenTransactionDTO extends ExplorerTransactionDTO
{
    public function contractAddress(): ?string
    {
        return strtolower($this->getOrFail('contract_address'));
    }

    public function tokenDecimals(): ?int
    {
        $value = $this->get('token_decimals');

        return $value !== null ? (int)$value : null;
    }

    public function tokenSymbol(): ?string
    {
        return $this->get('token_symbol');
    }

    public function tokenName(): ?string
    {
        return $this->get('token_name');
    }
}
