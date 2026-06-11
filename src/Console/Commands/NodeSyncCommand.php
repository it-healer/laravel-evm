<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Services\Sync\NodeSync;

class NodeSyncCommand extends Command
{
    protected $signature = 'evm:node-sync {node_id}';

    protected $description = 'Health check of one EVM node';

    public function handle(): void
    {
        $nodeId = (int)$this->argument('node_id');

        $this->line('-- Starting sync Node #'.$nodeId.' ...');

        try {
            /** @var class-string<EvmNode> $model */
            $model = Evm::getModel(EvmModel::Node);
            $node = $model::findOrFail($nodeId);

            $service = App::make(NodeSync::class, compact('node'));

            $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

            $service->run();
        } catch (\Exception $e) {
            $this->error('-- Error: '.$e->getMessage());
        }

        $this->line('-- Completed!');
    }
}
