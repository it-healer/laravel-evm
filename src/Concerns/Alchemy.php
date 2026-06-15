<?php

namespace ItHealer\LaravelEvm\Concerns;

use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmAlchemyWebhook;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\Alchemy\AlchemyNotifyClient;
use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;

/**
 * High-level management of Alchemy Address Activity webhooks.
 */
trait Alchemy
{
    public function alchemyNotify(): AlchemyNotifyClient
    {
        return app(AlchemyNotifyClient::class);
    }

    /**
     * The webhook record for a network, if one was created.
     */
    public function findAlchemyWebhook(EvmNetwork|int|string $network): ?EvmAlchemyWebhook
    {
        $network = $this->findNetwork($network);

        if (!$network) {
            return null;
        }

        /** @var class-string<EvmAlchemyWebhook> $model */
        $model = $this->getModel(EvmModel::AlchemyWebhook);

        return $model::query()->where('network_id', $network->id)->first();
    }

    /**
     * Ensure an Alchemy webhook exists for the network, creating it on first call.
     */
    public function ensureAlchemyWebhook(EvmNetwork|int|string $network): EvmAlchemyWebhook
    {
        $network = $this->findNetwork($network);

        if (!$network) {
            throw new \InvalidArgumentException('Network not found.');
        }

        if ($webhook = $this->findAlchemyWebhook($network)) {
            return $webhook;
        }

        $alchemyNetwork = AlchemyUrlFactory::network($network->chain_id);

        if (!$alchemyNetwork) {
            throw new \InvalidArgumentException(
                "Alchemy Notify does not support chain id {$network->chain_id}."
            );
        }

        $result = $this->alchemyNotify()->createWebhook($alchemyNetwork, $this->alchemyWebhookUrl());

        /** @var class-string<EvmAlchemyWebhook> $model */
        $model = $this->getModel(EvmModel::AlchemyWebhook);

        return $model::create([
            'network_id' => $network->id,
            'webhook_id' => $result['id'],
            'signing_key' => $result['signing_key'],
            'addresses_count' => 0,
            'active' => true,
        ]);
    }

    /**
     * Add an address to the network's Alchemy webhook (creating the webhook if needed).
     */
    public function subscribeAlchemyAddress(EvmAddress|string $address, EvmNetwork|int|string $network): void
    {
        $webhook = $this->ensureAlchemyWebhook($network);
        $value = $address instanceof EvmAddress ? $address->address : $address;

        $this->alchemyNotify()->updateAddresses($webhook->webhook_id, add: [$value]);
        $webhook->increment('addresses_count');
    }

    /**
     * Remove an address from the network's Alchemy webhook.
     */
    public function unsubscribeAlchemyAddress(EvmAddress|string $address, EvmNetwork|int|string $network): void
    {
        $webhook = $this->findAlchemyWebhook($network);

        if (!$webhook) {
            return;
        }

        $value = $address instanceof EvmAddress ? $address->address : $address;

        $this->alchemyNotify()->updateAddresses($webhook->webhook_id, remove: [$value]);
        $webhook->decrement('addresses_count');
    }

    /**
     * Reconcile the Alchemy webhook address list with the addresses the package tracks
     * in this network (the authoritative full sync). Returns the applied diff.
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    public function reconcileAlchemyWebhook(EvmNetwork|int|string $network): array
    {
        $network = $this->findNetwork($network);

        if (!$network) {
            throw new \InvalidArgumentException('Network not found.');
        }

        $webhook = $this->ensureAlchemyWebhook($network);

        $local = $this->alchemyTrackedAddresses($network);
        $localLower = $local->map(fn (string $a) => Str::lower($a));

        $remote = collect($this->alchemyNotify()->getAddresses($webhook->webhook_id));
        $remoteLower = $remote->map(fn (string $a) => Str::lower($a));

        $add = $local->filter(fn (string $a) => !$remoteLower->contains(Str::lower($a)))->values();
        $remove = $remote->filter(fn (string $a) => !$localLower->contains(Str::lower($a)))->values();

        if ($add->isNotEmpty() || $remove->isNotEmpty()) {
            $this->alchemyNotify()->updateAddresses($webhook->webhook_id, $add->all(), $remove->all());
        }

        $webhook->update(['addresses_count' => $local->count()]);

        return ['added' => $add->all(), 'removed' => $remove->all()];
    }

    /**
     * Addresses the package tracks in a network: available addresses of wallets
     * that have the network attached.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function alchemyTrackedAddresses(EvmNetwork $network): \Illuminate\Support\Collection
    {
        /** @var class-string<EvmAddress> $model */
        $model = $this->getModel(EvmModel::Address);

        return $model::query()
            ->where('available', true)
            ->whereHas('wallet.networks', fn ($query) => $query->where('evm_networks.id', $network->id))
            ->pluck('address');
    }

    protected function alchemyWebhookUrl(): string
    {
        $url = config('evm.alchemy.webhook.url');

        if ($url) {
            return $url;
        }

        return rtrim((string)config('app.url'), '/').'/'.ltrim((string)config('evm.alchemy.webhook.path'), '/');
    }
}