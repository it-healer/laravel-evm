<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmAlchemyWebhook;

class AlchemyReconcileCommand extends Command
{
    protected $signature = 'evm:alchemy-reconcile {network? : Network id, chain id or name (all configured networks by default)}';

    protected $description = 'Sync Alchemy webhook watched-address lists with the addresses the package tracks';

    public function handle(): int
    {
        foreach ($this->networks() as $network) {
            $this->line('-- Reconciling '.$network->name.' ...');

            try {
                $diff = Evm::reconcileAlchemyWebhook($network);
                $this->info('   +'.count($diff['added']).' / -'.count($diff['removed']).' addresses');
            } catch (\Throwable $e) {
                $this->error('   Error: '.$e->getMessage());
            }
        }

        $this->line('-- Completed!');

        return self::SUCCESS;
    }

    /**
     * @return iterable<\ItHealer\LaravelEvm\Models\EvmNetwork>
     */
    protected function networks(): iterable
    {
        $argument = $this->argument('network');

        if ($argument) {
            $network = Evm::findNetwork(is_numeric($argument) ? (int)$argument : $argument);

            if (!$network) {
                throw new \InvalidArgumentException('Network not found.');
            }

            return [$network];
        }

        /** @var class-string<EvmAlchemyWebhook> $model */
        $model = Evm::getModel(EvmModel::AlchemyWebhook);

        return $model::query()->where('active', true)->with('network')->get()
            ->pluck('network')
            ->filter();
    }
}
