<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelEvm\Casts\BigDecimalCast;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Enums\TransactionType;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmTransaction extends Model
{
    protected $fillable = [
        'network_id',
        'txid',
        'address',
        'type',
        'time_at',
        'from',
        'to',
        'amount',
        'token_address',
        'token_id',
        'block_number',
        'data',
    ];

    protected $appends = [
        'symbol',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'time_at' => 'datetime',
            'amount' => BigDecimalCast::class,
            'block_number' => 'integer',
            'data' => 'array',
        ];
    }

    public function network(): BelongsTo
    {
        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $this->belongsTo($model, 'network_id');
    }

    public function token(): BelongsTo
    {
        /** @var class-string<EvmToken> $model */
        $model = Evm::getModel(EvmModel::Token);

        return $this->belongsTo($model, 'token_id');
    }

    public function addresses(): HasMany
    {
        /** @var class-string<EvmAddress> $model */
        $model = Evm::getModel(EvmModel::Address);

        return $this->hasMany($model, 'address', 'address');
    }

    protected function symbol(): Attribute
    {
        return new Attribute(
            get: fn () => $this->token_address
                ? ($this->token?->symbol ?: 'TOKEN')
                : ($this->network?->currency_symbol ?: 'ETH')
        );
    }
}
