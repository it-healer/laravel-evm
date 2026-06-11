<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmWallet;
use ItHealer\LaravelEvm\Services\BaseSync;

/**
 * Synchronizes all addresses of one wallet within one network.
 */
class WalletNetworkSync extends BaseSync
{
    public function __construct(
        protected EvmWallet $wallet,
        protected EvmNetwork $network,
        protected bool $force = false,
    ) {
    }

    public function run(): void
    {
        parent::run();

        $addresses = $this->wallet
            ->addresses()
            ->where('available', true)
            ->get();

        foreach ($addresses as $address) {
            $this->checkCancelled();
            $this->log('- Started sync address '.$address->address.'...');

            $service = App::make(AddressNetworkSync::class, [
                'address' => $address,
                'network' => $this->network,
                'force' => $this->force,
            ]);

            $service->setLogger($this->logger)
                ->onProgress($this->progressCallback)
                ->cancelWhen($this->cancelCallback);

            $service->run();

            $this->log('- Finished sync address '.$address->address, 'success');
        }

        $this->wallet->update([
            'sync_at' => Date::now(),
        ]);
    }
}
