<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;

class AddressSyncCommand extends Command
{
    protected $signature = 'evm:address-sync {address_id} {--network= : Network id, chain id or name (active networks attached to the wallet by default)} {--force : Bypass the touch (TSS) check}';

    protected $description = 'Start EVM sync of one address';

    public function handle(): void
    {
        $addressId = (int)$this->argument('address_id');

        $this->line('-- Starting sync EVM address #'.$addressId.' ...');

        try {
            /** @var class-string<EvmAddress> $model */
            $model = Evm::getModel(EvmModel::Address);
            $address = $model::findOrFail($addressId);

            $this->line('-- Address: *'.$address->address.'* '.$address->title);

            foreach ($this->networks($address) as $network) {
                $this->line('-- Network: '.$network->name);

                $service = App::make(AddressNetworkSync::class, [
                    'address' => $address,
                    'network' => $network,
                    'force' => (bool)$this->option('force'),
                ]);

                $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

                $service->run();
            }
        } catch (\Exception $e) {
            $this->error('-- Error: '.$e->getMessage());
        }

        $this->line('-- Completed!');
    }

    /**
     * Without --network: only active networks attached to the address wallet.
     *
     * @return iterable<EvmNetwork>
     */
    protected function networks(EvmAddress $address): iterable
    {
        $networkOption = $this->option('network');

        if ($networkOption) {
            $network = Evm::findNetwork(is_numeric($networkOption) ? (int)$networkOption : $networkOption);

            if (!$network) {
                throw new \InvalidArgumentException('Network not found.');
            }

            return [$network];
        }

        return $address->wallet->networks()->where('active', true)->orderBy('name')->get();
    }
}
