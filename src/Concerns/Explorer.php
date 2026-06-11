<?php

namespace ItHealer\LaravelEvm\Concerns;

use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Exceptions\NetworkInactiveException;
use ItHealer\LaravelEvm\Models\EvmExplorer;
use ItHealer\LaravelEvm\Models\EvmNetwork;

trait Explorer
{
    public function createExplorer(
        EvmNetwork $network,
        ExplorerDriver $driver,
        string $name,
        ?string $apiKey = null,
        ?string $baseURL = null,
        ?string $title = null,
        ?string $proxy = null,
    ): EvmExplorer {
        /** @var class-string<EvmExplorer> $explorerModel */
        $explorerModel = $this->getModel(EvmModel::Explorer);

        /** @var EvmExplorer $explorer */
        $explorer = new $explorerModel([
            'driver' => $driver,
            'name' => $name,
            'title' => $title,
            'base_url' => $baseURL,
            'api_key' => $apiKey,
            'proxy' => $proxy,
            'requests' => 1,
            'worked' => true,
        ]);
        $explorer->network()->associate($network);

        if (!$explorer->api()->healthCheck()) {
            throw new \RuntimeException("Explorer {$name} health check failed.");
        }
        $explorer->save();

        return $explorer;
    }

    public function getExplorer(EvmNetwork|int|string $network): EvmExplorer
    {
        $network = $this->findNetwork($network);

        if (!$network?->active) {
            throw new NetworkInactiveException(
                $network ? "Network {$network->name} is inactive." : 'Network not found.'
            );
        }

        return $network->explorers()
            ->where('worked', '=', true)
            ->where('available', '=', true)
            ->orderBy('requests')
            ->firstOrFail();
    }
}
