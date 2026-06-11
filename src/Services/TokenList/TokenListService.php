<?php

namespace ItHealer\LaravelEvm\Services\TokenList;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Services\TokenList\DTO\TokenInfoDTO;
use ItHealer\LaravelEvm\Support\ProxyFormatter;

/**
 * Token catalogs in the https://tokenlists.org format,
 * used by the admin UI to pick tokens to track per network.
 */
class TokenListService
{
    public const CACHE_PREFIX = 'evm:tokenlist:';

    /**
     * @return Collection<int, TokenInfoDTO>
     */
    public function fetch(string $url, bool $fresh = false): Collection
    {
        $cacheKey = self::CACHE_PREFIX.sha1($url);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $tokens = Cache::remember(
            $cacheKey,
            (int)config('evm.chainlist.cache_ttl', 86400),
            fn () => $this->download($url)
        );

        return collect($tokens)->map(fn (array $token) => TokenInfoDTO::make($token));
    }

    /**
     * Tokens of one chain, merged from all configured (or one given) token lists.
     *
     * @return Collection<int, TokenInfoDTO>
     */
    public function forChain(int $chainId, ?string $url = null): Collection
    {
        $urls = $url ? [$url] : (array)config('evm.token_lists', []);

        return collect($urls)
            ->flatMap(fn (string $listUrl) => $this->fetch($listUrl))
            ->filter(fn (TokenInfoDTO $token) => $token->chainId() === $chainId)
            ->unique(fn (TokenInfoDTO $token) => $token->address())
            ->values();
    }

    protected function download(string $url): array
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->withOptions(['proxy' => ProxyFormatter::format(config('evm.proxy'))])
            ->get($url);

        $response->throw();

        $tokens = $response->json('tokens');

        if (!is_array($tokens)) {
            throw new \RuntimeException("Token list {$url} has no tokens array.");
        }

        return $tokens;
    }
}
