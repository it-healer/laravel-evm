<?php

namespace ItHealer\LaravelEvm\Observers;

use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Jobs\UpdateAlchemyAddressJob;
use ItHealer\LaravelEvm\Models\EvmAddress;

/**
 * Keeps Alchemy webhooks in sync with addresses as they are created/deleted.
 * Only networks that already have a webhook record are touched — the webhook
 * itself is provisioned explicitly via `evm:alchemy-setup`.
 */
class EvmAddressObserver
{
    public function created(EvmAddress $address): void
    {
        foreach ($this->webhookNetworks($address) as $network) {
            UpdateAlchemyAddressJob::dispatch($address, $network, UpdateAlchemyAddressJob::ADD);
        }
    }

    public function deleted(EvmAddress $address): void
    {
        foreach ($this->webhookNetworks($address) as $network) {
            UpdateAlchemyAddressJob::dispatch($address, $network, UpdateAlchemyAddressJob::REMOVE);
        }
    }

    /**
     * Active networks attached to the address wallet that already have a webhook.
     *
     * @return iterable<\ItHealer\LaravelEvm\Models\EvmNetwork>
     */
    protected function webhookNetworks(EvmAddress $address): iterable
    {
        /** @var class-string<\ItHealer\LaravelEvm\Models\EvmAlchemyWebhook> $webhookModel */
        $webhookModel = Evm::getModel(EvmModel::AlchemyWebhook);

        $networkIds = $webhookModel::query()->where('active', true)->pluck('network_id');

        if ($networkIds->isEmpty()) {
            return [];
        }

        return $address->wallet
            ->networks()
            ->where('active', true)
            ->whereIn('evm_networks.id', $networkIds)
            ->get();
    }
}
