<?php

namespace ItHealer\LaravelEvm\Concerns;

use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Exceptions\NetworkInactiveException;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;

trait Node
{
    public function createNode(
        EvmNetwork $network,
        string $name,
        string $baseURL,
        ?string $title = null,
        ?string $proxy = null,
        ?string $apiKey = null,
    ): EvmNode {
        /** @var class-string<EvmNode> $nodeModel */
        $nodeModel = $this->getModel(EvmModel::Node);

        /** @var EvmNode $node */
        $node = new $nodeModel([
            'name' => $name,
            'title' => $title,
            'base_url' => $baseURL,
            'api_key' => $apiKey,
            'proxy' => $proxy,
            'requests' => 1,
            'worked' => true,
        ]);
        $node->network()->associate($network);

        $node->api()->getLatestBlockNumber();
        $node->save();

        return $node;
    }

    public function createAlchemyNode(
        EvmNetwork $network,
        string $apiKey,
        string $name,
        ?string $title = null,
        ?string $proxy = null,
    ): EvmNode {
        $baseURL = AlchemyUrlFactory::make($network->chain_id, $apiKey);

        if (!$baseURL) {
            throw new \InvalidArgumentException(
                "Alchemy does not support chain id {$network->chain_id}."
            );
        }

        return $this->createNode($network, $name, $baseURL, $title, $proxy, $apiKey);
    }

    public function getNode(EvmNetwork|int|string $network): EvmNode
    {
        $network = $this->findNetwork($network);

        if (!$network?->active) {
            throw new NetworkInactiveException(
                $network ? "Network {$network->name} is inactive." : 'Network not found.'
            );
        }

        return $network->nodes()
            ->where('worked', '=', true)
            ->where('available', '=', true)
            ->orderBy('requests')
            ->firstOrFail();
    }
}
