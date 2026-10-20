<?php

namespace ItHealer\LaravelEvm\Explorer\Contracts;

use Closure;
use ItHealer\LaravelEvm\Api\DTOPaginator;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTransactionDTO;

/**
 * An explorer that knows internal transfers of the native coin: value a contract sent to an
 * address while executing a transaction (a bridge, an exchange withdrawal, a smart wallet, the
 * native coin of a swap). They are not transactions of the address, so txlist/external transfers
 * do not return them, although the balance grows.
 */
interface InternalTransfersInterface
{
    /**
     * Whether this explorer returns internal transfers on its network.
     */
    public function supportsInternalTransfers(): bool;

    /**
     * Internal native transfers to or from an address starting from a block (inclusive),
     * one item per transfer (several may share the hash of their transaction).
     *
     * @param Closure|null $onRequest called before every underlying API request
     * @return DTOPaginator<ExplorerTransactionDTO>
     */
    public function getInternalTransactions(
        string $address,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator;
}
