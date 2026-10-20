<?php

namespace ItHealer\LaravelEvm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;

/**
 * One-off backfill of internal transfers synced before they were supported. Safe to repeat:
 * rows are matched by transaction, so nothing is duplicated.
 */
class SyncInternalTransfersCommand extends Command
{
    protected $signature = 'evm:sync-internal
        {--network= : Network id, chain id or name (all active networks by default)}
        {--address= : Address id (all available addresses by default)}
        {--from-block=0 : Read from this block}
        {--webhooks : Also call the deposit webhook handler for incoming transfers found (merchant, notifications)}';

    protected $description = 'Read internal transfers of EVM addresses once more (backfill of the history)';

    public function handle(): int
    {
        $total = 0;

        foreach ($this->networks() as $network) {
            foreach ($this->addresses($network) as $address) {
                $this->line("-- {$network->name}: *{$address->address}*");

                try {
                    $service = App::make(AddressNetworkSync::class, [
                        'address' => $address,
                        'network' => $network,
                        'force' => true,
                    ]);

                    $service->setLogger(fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message));

                    $stored = $service->backfillInternalTransfers((int)$this->option('from-block'), (bool)$this->option('webhooks'));
                    $total += $stored;

                    $this->info("   internal transfers: {$stored}");
                } catch (\Exception $e) {
                    $this->error('   Error: '.$e->getMessage());
                }
            }
        }

        $this->line("-- Completed, internal transfers stored: {$total}");

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

        return $model::query()->where('active', true)->orderBy('name')->get();
    }

    /**
     * Available addresses of the wallets the network is attached to.
     *
     * @return iterable<EvmAddress>
     */
    protected function addresses(EvmNetwork $network): iterable
    {
        /** @var class-string<EvmAddress> $model */
        $model = Evm::getModel(EvmModel::Address);

        return $model::query()
            ->where('available', true)
            ->when($this->option('address'), fn ($query, $id) => $query->whereKey((int)$id))
            ->whereHas('wallet.networks', fn ($query) => $query->whereKey($network->getKey()))
            ->orderBy('id')
            ->get();
    }
}
