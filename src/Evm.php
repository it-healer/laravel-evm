<?php

namespace ItHealer\LaravelEvm;

use Illuminate\Database\Eloquent\Model;
use ItHealer\LaravelEvm\Concerns\Address;
use ItHealer\LaravelEvm\Concerns\Mnemonic;
use ItHealer\LaravelEvm\Enums\EvmModel;

class Evm
{
    use Mnemonic, Address;

    /**
     * @return class-string<Model>
     */
    public function getModel(EvmModel $model): string
    {
        return config('evm.models.'.$model->value);
    }
}
