<?php

namespace ItHealer\LaravelEvm;

use ItHealer\LaravelEvm\Console\Commands\AddressSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\EvmSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\ExplorerSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\NetworkSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\NodeSyncCommand;
use ItHealer\LaravelEvm\Console\Commands\WalletSyncCommand;
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
            ->hasCommands(
                NodeSyncCommand::class,
                ExplorerSyncCommand::class,
                AddressSyncCommand::class,
                WalletSyncCommand::class,
                NetworkSyncCommand::class,
                EvmSyncCommand::class,
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
}
