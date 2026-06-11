<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Services\Sync\NetworkSync;

class NetworkSyncCommand extends Command
{
    protected $signature = 'evm:network-sync {network : Network id, chain id or name}';

    protected $description = 'Start EVM sync of one network';

    public function handle(): void
    {
        $networkArg = $this->argument('network');
        $network = Evm::findNetwork(is_numeric($networkArg) ? (int)$networkArg : $networkArg);

        if (!$network) {
            $this->error('Network not found.');
            return;
        }

        $this->line('-- Starting sync Network '.$network->name.'...');

        try {
            $service = App::make(NetworkSync::class, compact('network'));

            $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

            $service->run();
        } catch (\Exception $e) {
            $this->error('-- Error: '.$e->getMessage());
        }

        $this->line('-- Completed!');
    }
}
