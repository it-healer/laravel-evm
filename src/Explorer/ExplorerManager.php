<?php

namespace ItHealer\LaravelEvm\Explorer;

use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Explorer\Contracts\ExplorerDriverInterface;
use ItHealer\LaravelEvm\Explorer\Drivers\AlchemyDriver;
use ItHealer\LaravelEvm\Explorer\Drivers\EtherscanV2Driver;
use ItHealer\LaravelEvm\Models\EvmExplorer;

class ExplorerManager
{
    public static function make(EvmExplorer $explorer): ExplorerDriverInterface
    {
        $network = $explorer->network;

        return match ($explorer->driver) {
            ExplorerDriver::EtherscanV2 => new EtherscanV2Driver(
                chainId: $network->chain_id,
                apiKey: (string)$explorer->api_key,
                baseURL: $explorer->base_url,
                proxy: $explorer->proxy ?? config('evm.proxy'),
                nativeDecimals: $network->currency_decimals,
            ),
            ExplorerDriver::Alchemy => new AlchemyDriver(
                baseURL: $explorer->base_url ?: throw new \InvalidArgumentException(
                    'Alchemy explorer requires base_url (https://{network}.g.alchemy.com/v2/{key}).'
                ),
                proxy: $explorer->proxy ?? config('evm.proxy'),
                nativeDecimals: $network->currency_decimals,
            ),
        };
    }
}
