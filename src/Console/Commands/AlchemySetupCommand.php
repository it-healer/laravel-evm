<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelEvm\Facades\Evm;

class AlchemySetupCommand extends Command
{
    protected $signature = 'evm:alchemy-setup {network : Network id, chain id or name} {--reconcile : Push all tracked addresses after creating the webhook}';

    protected $description = 'Create (or reuse) the Alchemy Address Activity webhook for a network';

    public function handle(): int
    {
        $network = Evm::findNetwork(is_numeric($this->argument('network'))
            ? (int)$this->argument('network')
            : $this->argument('network'));

        if (!$network) {
            $this->error('-- Network not found.');

            return self::FAILURE;
        }

        try {
            $webhook = Evm::ensureAlchemyWebhook($network);
        } catch (\Throwable $e) {
            $this->error('-- Error: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('-- Webhook ready for network '.$network->name);
        $this->line('   webhook_id: '.$webhook->webhook_id);
        $this->line('   receiver:   '.(config('evm.alchemy.webhook.url')
            ?: rtrim((string)config('app.url'), '/').'/'.ltrim((string)config('evm.alchemy.webhook.path'), '/')));

        if ($this->option('reconcile')) {
            $diff = Evm::reconcileAlchemyWebhook($network);
            $this->info('-- Reconciled: +'.count($diff['added']).' / -'.count($diff['removed']).' addresses');
        }

        return self::SUCCESS;
    }
}
