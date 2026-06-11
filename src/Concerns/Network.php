<?php

namespace ItHealer\LaravelEvm\Concerns;

use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Enums\TxType;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\ChainList\ChainListService;

trait Network
{
    public function createNetwork(
        int $chainId,
        string $name,
        string $currencySymbol,
        ?string $title = null,
        int $currencyDecimals = 18,
        ?string $explorerUrl = null,
        ?TxType $txType = null,
        ?int $confirmationsTarget = null,
        ?int $lagBlocks = null,
        ?int $blockTime = null,
    ): EvmNetwork {
        /** @var class-string<EvmNetwork> $networkModel */
        $networkModel = $this->getModel(EvmModel::Network);

        return $networkModel::create([
            'chain_id' => $chainId,
            'name' => $name,
            'title' => $title,
            'currency_symbol' => $currencySymbol,
            'currency_decimals' => $currencyDecimals,
            'explorer_url' => $explorerUrl,
            'tx_type' => $txType,
            'confirmations_target' => $confirmationsTarget ?? 12,
            'lag_blocks' => $lagBlocks,
            'block_time' => $blockTime,
            'active' => true,
        ]);
    }

    /**
     * Creates a network from the chainid.network catalog by its chain id.
     */
    public function createNetworkFromChainList(int $chainId, ?string $name = null): EvmNetwork
    {
        $chain = app(ChainListService::class)->find($chainId);

        if (!$chain) {
            throw new \InvalidArgumentException("Chain id {$chainId} not found in the chain list.");
        }

        return $this->createNetwork(
            chainId: $chain->chainId(),
            name: $name ?? Str::slug($chain->shortName() ?: $chain->name()),
            currencySymbol: $chain->currencySymbol(),
            title: $chain->name(),
            currencyDecimals: $chain->currencyDecimals(),
            explorerUrl: $chain->explorers()[0]['url'] ?? null,
        );
    }

    public function findNetwork(EvmNetwork|int|string $network): ?EvmNetwork
    {
        if ($network instanceof EvmNetwork) {
            return $network;
        }

        /** @var class-string<EvmNetwork> $networkModel */
        $networkModel = $this->getModel(EvmModel::Network);

        if (is_int($network)) {
            return $networkModel::query()
                ->where('id', $network)
                ->orWhere('chain_id', $network)
                ->orderByRaw('chain_id = ? desc', [$network])
                ->first();
        }

        return $networkModel::query()->where('name', $network)->first();
    }
}
