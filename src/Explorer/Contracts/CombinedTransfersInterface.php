<?php

namespace ItHealer\LaravelEvm\Explorer\Contracts;

use Closure;
use ItHealer\LaravelEvm\Api\DTOPaginator;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTokenTransactionDTO;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTransactionDTO;

/**
 * An explorer that returns native and ERC-20 transfers of an address in one query
 * (Alchemy: several categories per alchemy_getAssetTransfers call), so a sync pays
 * for one request per direction instead of one per direction and asset kind.
 */
interface CombinedTransfersInterface
{
    /**
     * Native transfers, plus ERC-20 transfers when `$withTokens` is true, starting from
     * a block (inclusive). Token transfers are ExplorerTokenTransactionDTO.
     *
     * @param Closure|null $onRequest called before every underlying API request
     * @return DTOPaginator<ExplorerTransactionDTO|ExplorerTokenTransactionDTO>
     */
    public function getTransfers(
        string $address,
        bool $withTokens,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator;
}
