<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmExplorer;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Models\EvmWallet;
use ItHealer\LaravelEvm\Services\BaseSync;

/**
 * Synchronizes one network: its nodes and explorers health,
 * then every wallet within this network.
 */
class NetworkSync extends BaseSync
{
    public function __construct(protected EvmNetwork $network)
    {
    }

    public function run(): void
    {
        parent::run();

        $this
            ->syncNodes()
            ->syncExplorers()
            ->syncWallets();

        $this->network->update([
            'sync_at' => Date::now(),
        ]);
    }

    protected function syncNodes(): static
    {
        $this->network->nodes()
            ->where('available', true)
            ->orderBy('sync_at')
            ->orderBy('name')
            ->each(function (EvmNode $node) {
                $this->log('--- Starting sync Node '.$node->name.'...');

                try {
                    $service = App::make(NodeSync::class, compact('node'));
                    $service->setLogger($this->logger);
                    $service->run();

                    $this->log('--- Finished sync Node '.$node->name, 'success');
                } catch (\Exception $e) {
                    $this->log('--- Node '.$node->name.' error: '.$e->getMessage(), 'error');
                }
            });

        return $this;
    }

    protected function syncExplorers(): static
    {
        $this->network->explorers()
            ->where('available', true)
            ->orderBy('sync_at')
            ->orderBy('name')
            ->each(function (EvmExplorer $explorer) {
                $this->log('--- Starting sync Explorer '.$explorer->name.'...');

                try {
                    $service = App::make(ExplorerSync::class, compact('explorer'));
                    $service->setLogger($this->logger);
                    $service->run();

                    $this->log('--- Finished sync Explorer '.$explorer->name, 'success');
                } catch (\Exception $e) {
                    $this->log('--- Explorer '.$explorer->name.' error: '.$e->getMessage(), 'error');
                }
            });

        return $this;
    }

    protected function syncWallets(): static
    {
        /** @var class-string<EvmWallet> $model */
        $model = Evm::getModel(EvmModel::Wallet);

        $model::query()
            ->orderBy('sync_at')
            ->orderBy('name')
            ->each(function (EvmWallet $wallet) {
                $this->checkCancelled();
                $this->log('--- Starting sync Wallet '.$wallet->name.'...');

                try {
                    $service = App::make(WalletNetworkSync::class, [
                        'wallet' => $wallet,
                        'network' => $this->network,
                    ]);

                    $service->setLogger($this->logger)
                        ->onProgress($this->progressCallback)
                        ->cancelWhen($this->cancelCallback);

                    $service->run();

                    $this->log('--- Finished sync Wallet '.$wallet->name, 'success');
                } catch (\ItHealer\LaravelEvm\Exceptions\SyncCancelledException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    $this->log('--- Wallet '.$wallet->name.' error: '.$e->getMessage(), 'error');
                }
            });

        return $this;
    }
}
