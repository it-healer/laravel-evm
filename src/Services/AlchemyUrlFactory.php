<?php

namespace ItHealer\LaravelEvm\Services;

class AlchemyUrlFactory
{
    /**
     * Alchemy network slugs by EVM chain id.
     * https://docs.alchemy.com/reference/api-overview
     */
    public const SLUGS = [
        1 => 'eth-mainnet',
        10 => 'opt-mainnet',
        56 => 'bnb-mainnet',
        100 => 'gnosis-mainnet',
        137 => 'polygon-mainnet',
        250 => 'fantom-mainnet',
        324 => 'zksync-mainnet',
        8453 => 'base-mainnet',
        42161 => 'arb-mainnet',
        42220 => 'celo-mainnet',
        43114 => 'avax-mainnet',
        59144 => 'linea-mainnet',
        534352 => 'scroll-mainnet',
        81457 => 'blast-mainnet',
        11155111 => 'eth-sepolia',
        84532 => 'base-sepolia',
        421614 => 'arb-sepolia',
        80002 => 'polygon-amoy',
        11155420 => 'opt-sepolia',
    ];

    public static function supports(int $chainId): bool
    {
        return isset(self::SLUGS[$chainId]);
    }

    public static function make(int $chainId, string $apiKey): ?string
    {
        $slug = self::SLUGS[$chainId] ?? null;

        return $slug ? "https://{$slug}.g.alchemy.com/v2/{$apiKey}" : null;
    }
}
