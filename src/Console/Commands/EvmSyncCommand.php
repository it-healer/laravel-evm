<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use ItHealer\LaravelEvm\Services\Sync\EvmSync;

class EvmSyncCommand extends Command
{
    protected $signature = 'evm:sync';

    protected $description = 'Start EVM sync of all active networks';

    public function handle(): void
    {
        Cache::lock('evm-sync', 300)->get(function () {
            $this->line('---- Starting sync EVM...');

            try {
                $service = App::make(EvmSync::class);

                $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

                $service->run();
            } catch (\Exception $e) {
                $this->error('---- Error: '.$e->getMessage());
            }

            $this->line('---- Completed!');
        });
    }
}
