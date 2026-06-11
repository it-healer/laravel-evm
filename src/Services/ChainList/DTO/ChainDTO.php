<?php

namespace ItHealer\LaravelEvm\Services\ChainList\DTO;

use ItHealer\LaravelEvm\Api\BaseDTO;

/**
 * One chain entry of https://chainid.network/chains.json.
 */
class ChainDTO extends BaseDTO
{
    public function chainId(): int
    {
        return (int)$this->getOrFail('chainId');
    }

    public function name(): string
    {
        return (string)$this->getOrFail('name');
    }

    public function shortName(): string
    {
        return (string)$this->get('shortName', '');
    }

    public function currencySymbol(): string
    {
        return (string)($this->get('nativeCurrency')['symbol'] ?? 'ETH');
    }

    public function currencyName(): string
    {
        return (string)($this->get('nativeCurrency')['name'] ?? '');
    }

    public function currencyDecimals(): int
    {
        return (int)($this->get('nativeCurrency')['decimals'] ?? 18);
    }

    /**
     * Public RPC endpoints. Entries with API-key placeholders (`${...}`)
     * and websocket URLs are filtered out by default.
     *
     * @return array<string>
     */
    public function rpcUrls(bool $publicOnly = true): array
    {
        $urls = $this->get('rpc', []);

        if (!$publicOnly) {
            return $urls;
        }

        return array_values(array_filter(
            $urls,
            fn (string $url) => !str_contains($url, '${') && str_starts_with($url, 'http')
        ));
    }

    /**
     * @return array<array{name: string, url: string, standard?: string}>
     */
    public function explorers(): array
    {
        return $this->get('explorers', []);
    }

    public function infoUrl(): ?string
    {
        return $this->get('infoURL');
    }

    public function isTestnet(): bool
    {
        $haystack = strtolower($this->name().' '.$this->shortName().' '.json_encode($this->get('slip44', '')));

        return str_contains($haystack, 'testnet')
            || str_contains($haystack, 'sepolia')
            || str_contains($haystack, 'goerli');
    }
}
