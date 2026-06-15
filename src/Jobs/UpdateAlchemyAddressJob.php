<?php

namespace ItHealer\LaravelEvm\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;

/**
 * Adds or removes a single address in the network's Alchemy webhook. Dispatched by
 * the address observer when `evm.alchemy.auto_subscribe` is enabled.
 */
class UpdateAlchemyAddressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ADD = 'add';
    public const REMOVE = 'remove';

    public function __construct(
        public EvmAddress $address,
        public EvmNetwork $network,
        public string $action,
    ) {
        $this->onConnection(config('evm.alchemy.queue.connection'));
        $this->onQueue(config('evm.alchemy.queue.name'));
    }

    public function handle(): void
    {
        if ($this->action === self::REMOVE) {
            Evm::unsubscribeAlchemyAddress($this->address->address, $this->network);

            return;
        }

        Evm::subscribeAlchemyAddress($this->address->address, $this->network);
    }
}
