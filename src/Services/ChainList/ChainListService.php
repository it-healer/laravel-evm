<?php

namespace ItHealer\LaravelEvm\Services\ChainList;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Services\ChainList\DTO\ChainDTO;

/**
 * Catalog of all known EVM networks (https://chainid.network),
 * used by the admin UI to pick networks to add.
 */
class ChainListService
{
    public const CACHE_KEY = 'evm:chainlist';

    /**
     * @return Collection<int, ChainDTO>
     */
    public function all(bool $fresh = false): Collection
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $data = Cache::remember(
            self::CACHE_KEY,
            (int)config('evm.chainlist.cache_ttl', 86400),
            fn () => $this->fetch()
        );

        return collect($data)->map(fn (array $chain) => ChainDTO::make($chain));
    }

    public function find(int $chainId): ?ChainDTO
    {
        return $this->all()->first(fn (ChainDTO $chain) => $chain->chainId() === $chainId);
    }

    /**
     * Search by name, short name or native currency symbol.
     *
     * @return Collection<int, ChainDTO>
     */
    public function search(string $query): Collection
    {
        $query = strtolower($query);

        return $this->all()
            ->filter(fn (ChainDTO $chain) => str_contains(strtolower($chain->name()), $query)
                || str_contains(strtolower($chain->shortName()), $query)
                || str_contains(strtolower($chain->currencySymbol()), $query))
            ->values();
    }

    protected function fetch(): array
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->get((string)config('evm.chainlist.url', 'https://chainid.network/chains.json'));

        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            throw new \RuntimeException('Unexpected chain list response.');
        }

        return $data;
    }
}
