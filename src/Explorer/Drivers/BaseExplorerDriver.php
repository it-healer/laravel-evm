<?php

namespace ItHealer\LaravelEvm\Explorer\Drivers;

use ItHealer\LaravelEvm\Explorer\Contracts\ExplorerDriverInterface;
use ItHealer\LaravelEvm\Support\ProxyFormatter;

abstract class BaseExplorerDriver implements ExplorerDriverInterface
{
    protected function formatProxy(?string $proxy): ?string
    {
        return ProxyFormatter::format($proxy);
    }
}
