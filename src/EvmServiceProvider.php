<?php

namespace ItHealer\LaravelEvm;

use ItHealer\LaravelEvm\Console\Commands\AddressSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\AlchemyReconcileCommand;
use ItHealer\LaravelEvm\Console\Commands\AlchemySetupCommand;
use ItHealer\LaravelEvm\Console\Commands\ConfirmDepositsCommand;
use ItHealer\LaravelEvm\Console\Commands\EvmSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\ExplorerSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\NetworkSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\NodeSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\WalletSyncCommand;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Observers\EvmAddressObserver;
use ItHealer\LaravelEvm\Services\Alchemy\AlchemyNotifyClient;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EvmServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('evm')
            ->hasConfigFile()
            ->hasRoute('webhook')
            ->hasCommands(
                NodeSyncCommand::class,
                ExplorerSyncCommand::class,
                AddressSyncCommand::class,
                WalletSyncCommand::class,
                NetworkSyncCommand::class,
                EvmSyncCommand::class,
                AlchemySetupCommand::class,
                AlchemyReconcileCommand::class,
                ConfirmDepositsCommand::class,
            )
            ->discoversMigrations()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('it-healer/laravel-evm');
            });

        $this->app->singleton(Evm::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(AlchemyNotifyClient::class, function () {
            return new AlchemyNotifyClient(
                authToken: (string)config('evm.alchemy.auth_token'),
                apiUrl: (string)config('evm.alchemy.api_url', 'https://dashboard.alchemy.com/api'),
                proxy: config('evm.proxy'),
            );
        });
    }

    public function packageBooted(): void
    {
        if (config('evm.alchemy.auto_subscribe', false)) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $addressModel */
            $addressModel = config('evm.models.'.EvmModel::Address->value);

            $addressModel::observe(EvmAddressObserver::class);
        }
    }
}
