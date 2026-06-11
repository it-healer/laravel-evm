<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmToken extends Model
{
    protected $fillable = [
        'network_id',
        'address',
        'name',
        'symbol',
        'decimals',
        'logo_uri',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function network(): BelongsTo
    {
        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $this->belongsTo($model, 'network_id');
    }
}
