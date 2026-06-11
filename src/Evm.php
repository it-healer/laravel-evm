<?php

namespace ItHealer\LaravelEvm;

use Illuminate\Database\Eloquent\Model;
use ItHealer\LaravelEvm\Enums\EvmModel;

class Evm
{
    /**
     * @return class-string<Model>
     */
    public function getModel(EvmModel $model): string
    {
        return config('evm.models.'.$model->value);
    }
}
