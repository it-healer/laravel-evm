<?php

namespace ItHealer\LaravelEvm\Api;

use Closure;
use Generator;
use IteratorAggregate;

/**
 * Lazy iterator over API results that hides the pagination mechanism:
 * page numbers (Etherscan), cursors (Alchemy pageKey) or a raw generator.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
class DTOPaginator implements IteratorAggregate
{
    protected function __construct(protected Closure $generatorFactory)
    {
    }

    /**
     * Page-number based pagination: $callback(int $page): array<T>.
     * Stops when a page is empty or shorter than $perPage.
     */
    public static function pages(Closure $callback, int $perPage = 10): static
    {
        return new static(function () use ($callback, $perPage): Generator {
            $page = 1;

            while (true) {
                $items = $callback($page);

                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    yield $item;
                }

                if (count($items) < $perPage) {
                    break;
                }

                $page++;
            }
        });
    }

    /**
     * Cursor based pagination:
     * $callback(?string $cursor): array{items: array<T>, next: ?string}.
     * Stops when `next` is null.
     */
    public static function cursor(Closure $callback): static
    {
        return new static(function () use ($callback): Generator {
            $cursor = null;

            do {
                ['items' => $items, 'next' => $cursor] = $callback($cursor);

                foreach ($items as $item) {
                    yield $item;
                }
            } while ($cursor !== null);
        });
    }

    /**
     * Arbitrary generator source, e.g. a concatenation of several paginators.
     */
    public static function generator(Closure $makeGenerator): static
    {
        return new static($makeGenerator);
    }

    public function getIterator(): Generator
    {
        // Re-yield without keys: inner generators/arrays may repeat keys
        // (page-local indexes), which would collide in iterator_to_array().
        foreach (($this->generatorFactory)() as $item) {
            yield $item;
        }
    }
}
