<?php

namespace ItHealer\LaravelEvm\Services\TokenList\DTO;

use ItHealer\LaravelEvm\Api\BaseDTO;

/**
 * One token entry of a https://tokenlists.org format list.
 */
class TokenInfoDTO extends BaseDTO
{
    public function chainId(): int
    {
        return (int)$this->getOrFail('chainId');
    }

    public function address(): string
    {
        return strtolower($this->getOrFail('address'));
    }

    public function name(): string
    {
        return (string)$this->getOrFail('name');
    }

    public function symbol(): string
    {
        return (string)$this->getOrFail('symbol');
    }

    public function decimals(): int
    {
        return (int)$this->getOrFail('decimals');
    }

    public function logoUri(): ?string
    {
        return $this->get('logoURI');
    }
}
