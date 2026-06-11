<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmWallet;
use ItHealer\LaravelEvm\Services\Sync\WalletNetworkSync;

class WalletSyncCommand extends Command
{
    protected $signature = 'evm:wallet-sync {wallet_id} {--network= : Network id, chain id or name (all active networks by default)}';

    protected $description = 'Start EVM sync of one wallet';

    public function handle(): void
    {
        $walletId = (int)$this->argument('wallet_id');

        $this->line('-- Starting sync Wallet #'.$walletId.' ...');

        try {
            /** @var class-string<EvmWallet> $model */
            $model = Evm::getModel(EvmModel::Wallet);
            $wallet = $model::findOrFail($walletId);

            foreach ($this->networks() as $network) {
                $this->line('-- Network: '.$network->name);

                $service = App::make(WalletNetworkSync::class, compact('wallet', 'network'));

                $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

                $service->run();
            }
        } catch (\Exception $e) {
            $this->error('-- Error: '.$e->getMessage());
        }

        $this->line('-- Completed!');
    }

    /**
     * @return iterable<EvmNetwork>
     */
    protected function networks(): iterable
    {
        $networkOption = $this->option('network');

        if ($networkOption) {
            $network = Evm::findNetwork(is_numeric($networkOption) ? (int)$networkOption : $networkOption);

            if (!$network) {
                throw new \InvalidArgumentException('Network not found.');
            }

            return [$network];
        }

        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $model::query()->where('active', true)->orderBy('name')->get();
    }
}
