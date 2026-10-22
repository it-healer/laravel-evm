<?php

namespace ItHealer\LaravelEvm\Services\Alchemy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;
use ItHealer\LaravelEvm\Services\ChainList\ChainListService;
use ItHealer\LaravelEvm\Support\ProxyFormatter;

/**
 * Finds the Alchemy URL slug of a network the built-in list does not know: guesses slugs from
 * the network names (chainid.network catalog, the network itself) and keeps only one whose
 * `eth_chainId` answers with the chain id of the network — a wrong network cannot slip in.
 * The found slug is saved to the network (`alchemy_slug`).
 */
class AlchemySlugFinder
{
    /** At most this many slugs are tried */
    public const MAX_CANDIDATES = 10;

    public function __construct(protected ChainListService $chainList) {}

    public function find(EvmNetwork $network, string $apiKey, ?string $proxy = null): ?string
    {
        if (($known = AlchemyUrlFactory::slugFor($network)) !== null) {
            return $known;
        }

        foreach ($this->candidates($network) as $slug) {
            if ($this->answers($slug, $apiKey, (int)$network->chain_id, $proxy)) {
                $network->forceFill(['alchemy_slug' => $slug])->save();

                return $slug;
            }
        }

        return null;
    }

    /**
     * Slugs to try: `{name}-mainnet` and `{name}` for the network names with and without the
     * generic words (chain, network, mainnet); testnets also `-testnet` / `-sepolia`.
     *
     * @return list<string>
     */
    public function candidates(EvmNetwork $network): array
    {
        $chain = rescue(fn () => $this->chainList->find((int)$network->chain_id), report: false);
        $testnet = $chain?->isTestnet() ?? false;

        $names = array_filter([$chain?->name(), $chain?->shortName(), $network->title, $network->name]);
        $suffixes = $testnet ? ['testnet', 'sepolia'] : ['mainnet'];
        $slugs = [];

        foreach ($names as $name) {
            $base = trim(Str::of((string)$name)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->toString(), '-');
            $short = trim(preg_replace('/(^|-)(chain|network|mainnet|testnet|l2)(?=-|$)/', '', $base) ?? '', '-');

            foreach (array_unique(array_filter([$short, $base])) as $root) {
                foreach ($suffixes as $suffix) {
                    $slugs[] = $root.'-'.$suffix;
                }
                $slugs[] = $root;
            }
        }

        return array_slice(array_values(array_unique(array_filter($slugs, fn (string $slug) => preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) === 1))), 0, self::MAX_CANDIDATES);
    }

    /**
     * The slug is the network: its URL answers `eth_chainId` with the chain id.
     */
    protected function answers(string $slug, string $apiKey, int $chainId, ?string $proxy): bool
    {
        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withOptions(array_filter(['proxy' => ProxyFormatter::format($proxy)]))
                ->post("https://{$slug}.g.alchemy.com/v2/{$apiKey}", [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_chainId',
                    'params' => [],
                ]);

            $result = $response->successful() ? $response->json('result') : null;

            return is_string($result) && str_starts_with($result, '0x') && hexdec($result) === $chainId;
        } catch (\Throwable) {
            return false;
        }
    }
}
