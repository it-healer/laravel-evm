<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Facades\Evm;
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
            ->syncable()
            ->get();

        $services = [];

        foreach ($addresses as $address) {
            $services[] = App::make(AddressNetworkSync::class, [
                'address' => $address,
                'network' => $this->network,
                'force' => $this->force,
            ])
                ->setLogger($this->logger)
                ->onProgress($this->progressCallback)
                ->cancelWhen($this->cancelCallback);
        }

        $this->prefetchBalances($services);

        foreach ($services as $service) {
            $this->checkCancelled();
            $this->log('- Started sync address '.$service->address()->address.'...');

            $service->run();

            $this->log('- Finished sync address '.$service->address()->address, 'success');
        }

        $this->wallet->update([
            'sync_at' => Date::now(),
        ]);
    }

    /**
     * Balances of every address due for a full sync in one Multicall3 request instead of
     * one eth_getBalance and one eth_call per token each. Without Multicall3 on the network,
     * or if the request fails, every address asks its balances itself as before.
     *
     * @param  list<AddressNetworkSync>  $services
     */
    protected function prefetchBalances(array $services): void
    {
        $due = array_values(array_filter($services, fn (AddressNetworkSync $service) => $service->needsFullSync()));

        if ($due === []) {
            return;
        }

        try {
            $batch = Evm::getNode($this->network)->getBalancesBatch(
                array_map(fn (AddressNetworkSync $service) => $service->address(), $due),
                $due[0]->tokens(),
            );
        } catch (\Throwable $e) {
            $this->log('Multicall3 balances failed, asking them one by one: '.$e->getMessage(), 'error');

            return;
        }

        if ($batch === null) {
            return;
        }

        foreach ($due as $service) {
            $item = $batch[Str::lower($service->address()->address)] ?? null;

            if ($item !== null) {
                $service->usePrefetchedBalances($item['native'], $item['tokens']);
            }
        }

        $this->log('Balances of '.count($due).' address(es) read with Multicall3.', 'success');
    }
}
