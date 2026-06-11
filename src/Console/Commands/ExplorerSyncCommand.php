<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmExplorer;
use ItHealer\LaravelEvm\Services\Sync\ExplorerSync;

class ExplorerSyncCommand extends Command
{
    protected $signature = 'evm:explorer-sync {explorer_id}';

    protected $description = 'Health check of one EVM explorer';

    public function handle(): void
    {
        $explorerId = (int)$this->argument('explorer_id');

        $this->line('-- Starting sync Explorer #'.$explorerId.' ...');

        try {
            /** @var class-string<EvmExplorer> $model */
            $model = Evm::getModel(EvmModel::Explorer);
            $explorer = $model::findOrFail($explorerId);

            $service = App::make(ExplorerSync::class, compact('explorer'));

            $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

            $service->run();
        } catch (\Exception $e) {
            $this->error('-- Error: '.$e->getMessage());
        }

        $this->line('-- Completed!');
    }
}
