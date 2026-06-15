<?php

namespace ItHealer\LaravelEvm\Services\Alchemy;

/**
 * Compute Unit (CU) cost per RPC method, used to meter credits spent on each node and
 * explorer. The values mirror Alchemy's published costs; for non-Alchemy providers the
 * same table is a reasonable weight to balance load across nodes by least-credits.
 *
 * Override individual methods via config('evm.compute_units').
 * https://www.alchemy.com/docs/reference/compute-unit-costs
 */
class ComputeUnits
{
    /** @var array<string, int> */
    public const COSTS = [
        'eth_blockNumber' => 10,
        'eth_getBalance' => 19,
        'eth_call' => 26,
        'eth_getBlockByNumber' => 16,
        'eth_getTransactionCount' => 19,
        'eth_getTransactionReceipt' => 15,
        'eth_gasPrice' => 19,
        'eth_maxPriorityFeePerGas' => 19,
        'eth_feeHistory' => 18,
        'eth_estimateGas' => 87,
        'eth_sendRawTransaction' => 250,
        'eth_getLogs' => 75,
        'alchemy_getAssetTransfers' => 150,
    ];

    public const DEFAULT_COST = 20;

    public static function cost(string $method): int
    {
        $overrides = (array)config('evm.compute_units', []);

        return (int)($overrides[$method] ?? self::COSTS[$method] ?? self::DEFAULT_COST);
    }
}
