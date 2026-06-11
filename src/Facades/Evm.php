<?php

namespace ItHealer\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;

class Evm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ItHealer\LaravelEvm\Evm::class;
    }
}
