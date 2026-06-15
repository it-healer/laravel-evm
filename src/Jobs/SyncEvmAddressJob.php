<?php

namespace ItHealer\LaravelEvm\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;

/**
 * Runs a targeted AddressNetworkSync for one (address, network), triggered by an
 * inbound Alchemy webhook. Unique per address+network so a burst of activity does
 * not spawn overlapping scans.
 */
class SyncEvmAddressJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(
        public EvmAddress $address,
        public EvmNetwork $network,
        public bool $force = true,
    ) {
        $this->onConnection(config('evm.alchemy.queue.connection'));
        $this->onQueue(config('evm.alchemy.queue.name'));
    }

    public function uniqueId(): string
    {
        return 'evm-sync:'.$this->address->getKey().':'.$this->network->getKey();
    }

    public function handle(): void
    {
        App::make(AddressNetworkSync::class, [
            'address' => $this->address,
            'network' => $this->network,
            'force' => $this->force,
        ])->run();
    }
}
