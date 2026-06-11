<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Exceptions\SyncCancelledException;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\BaseSync;

/**
 * Top-level sync: every active network in turn.
 */
class EvmSync extends BaseSync
{
    public function run(): void
    {
        parent::run();

        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        $model::query()
            ->where('active', true)
            ->orderBy('sync_at')
            ->orderBy('name')
            ->each(function (EvmNetwork $network) {
                $this->checkCancelled();
                $this->log('-- Starting sync Network '.$network->name.'...');

                try {
                    $service = App::make(NetworkSync::class, compact('network'));

                    $service->setLogger($this->logger)
                        ->onProgress($this->progressCallback)
                        ->cancelWhen($this->cancelCallback);

                    $service->run();

                    $this->log('-- Finished sync Network '.$network->name, 'success');
                } catch (SyncCancelledException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    $this->log('-- Network '.$network->name.' error: '.$e->getMessage(), 'error');
                }
            });
    }
}
