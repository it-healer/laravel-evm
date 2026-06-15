<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;

/**
 * Address Activity webhooks fire once (when a tx is mined) and are NOT re-sent as
 * confirmations grow. This command re-syncs only the addresses that still have a
 * deposit below the network confirmations target — cheap, targeted top-up so the
 * webhook flow can reach the confirmation threshold your handler waits for.
 */
class ConfirmDepositsCommand extends Command
{
    protected $signature = 'evm:confirm-deposits {--network= : Network id, chain id or name (all active networks by default)}';

    protected $description = 'Re-sync addresses that still have deposits below the confirmations target';

    public function handle(): int
    {
        /** @var class-string<EvmDeposit> $depositModel */
        $depositModel = Evm::getModel(EvmModel::Deposit);

        foreach ($this->networks() as $network) {
            $addressIds = $depositModel::query()
                ->where('network_id', $network->id)
                ->where('confirmations', '<', $network->confirmations_target)
                ->distinct()
                ->pluck('address_id');

            if ($addressIds->isEmpty()) {
                continue;
            }

            $this->line('-- '.$network->name.': '.$addressIds->count().' address(es) to confirm');

            /** @var class-string<\ItHealer\LaravelEvm\Models\EvmAddress> $addressModel */
            $addressModel = Evm::getModel(EvmModel::Address);

            foreach ($addressModel::query()->whereIn('id', $addressIds)->get() as $address) {
                try {
                    App::make(AddressNetworkSync::class, [
                        'address' => $address,
                        'network' => $network,
                        'force' => true,
                    ])->run();
                } catch (\Throwable $e) {
                    $this->error('   Address #'.$address->id.': '.$e->getMessage());
                }
            }
        }

        $this->line('-- Completed!');

        return self::SUCCESS;
    }

    /**
     * @return iterable<EvmNetwork>
     */
    protected function networks(): iterable
    {
        $option = $this->option('network');

        if ($option) {
            $network = Evm::findNetwork(is_numeric($option) ? (int)$option : $option);

            if (!$network) {
                throw new \InvalidArgumentException('Network not found.');
            }

            return [$network];
        }

        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $model::query()->where('active', true)->get();
    }
}
