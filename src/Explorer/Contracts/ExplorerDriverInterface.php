<?php

namespace ItHealer\LaravelEvm\Explorer\Contracts;

use Closure;
use ItHealer\LaravelEvm\Api\DTOPaginator;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTokenTransactionDTO;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTransactionDTO;

interface ExplorerDriverInterface
{
    /**
     * Native coin transactions of an address starting from a block (inclusive).
     *
     * @param Closure|null $onRequest called before every underlying API request
     * @return DTOPaginator<ExplorerTransactionDTO>
     */
    public function getNativeTransactions(
        string $address,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator;

    /**
     * ERC-20 transfers of an address starting from a block (inclusive),
     * optionally limited to one token contract.
     *
     * @param Closure|null $onRequest called before every underlying API request
     * @return DTOPaginator<ExplorerTokenTransactionDTO>
     */
    public function getTokenTransactions(
        string $address,
        ?string $contract = null,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator;

    public function healthCheck(): bool;

    /**
     * Compute Units billed per underlying API request (0 for non-CU providers).
     * Used to meter credits spent on the explorer.
     */
    public function creditsPerRequest(): int;
}
