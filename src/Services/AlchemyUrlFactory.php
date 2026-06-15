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

    /**
     * Alchemy Notify "network" identifiers by EVM chain id (used when creating
     * Address Activity webhooks). These differ from the RPC URL slugs above
     * (e.g. chain 137 is slug "polygon-mainnet" but Notify network "MATIC_MAINNET").
     * https://www.alchemy.com/docs/reference/notify-api-quickstart
     */
    public const NETWORKS = [
        1 => 'ETH_MAINNET',
        10 => 'OPT_MAINNET',
        56 => 'BNB_MAINNET',
        100 => 'GNOSIS_MAINNET',
        137 => 'MATIC_MAINNET',
        250 => 'FANTOM_MAINNET',
        324 => 'ZKSYNC_MAINNET',
        8453 => 'BASE_MAINNET',
        42161 => 'ARB_MAINNET',
        42220 => 'CELO_MAINNET',
        43114 => 'AVAX_MAINNET',
        59144 => 'LINEA_MAINNET',
        534352 => 'SCROLL_MAINNET',
        81457 => 'BLAST_MAINNET',
        11155111 => 'ETH_SEPOLIA',
        84532 => 'BASE_SEPOLIA',
        421614 => 'ARB_SEPOLIA',
        80002 => 'MATIC_AMOY',
        11155420 => 'OPT_SEPOLIA',
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

    /**
     * The Alchemy Notify network identifier for a chain id, or null if unknown.
     */
    public static function network(int $chainId): ?string
    {
        return self::NETWORKS[$chainId] ?? null;
    }

    public static function supportsNotify(int $chainId): bool
    {
        return isset(self::NETWORKS[$chainId]);
    }
}
