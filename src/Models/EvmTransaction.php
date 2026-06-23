<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'fee',
        'token_address',
        'token_id',
        'block_number',
        'nonce',
        'dropped_at',
        'failed',
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
            'fee' => BigDecimalCast::class,
            'block_number' => 'integer',
            'nonce' => 'integer',
            'dropped_at' => 'datetime',
            'failed' => 'boolean',
            'data' => 'array',
        ];
    }

    /**
     * Outgoing transfers that have been broadcast but are not yet mined
     * (no block_number) and have not been reconciled as dropped/replaced.
     * Their amount and fee are still "in flight" and must be subtracted
     * from the confirmed on-chain balance to show a truthful available balance.
     */
    public function scopePendingOutgoing(Builder $query): Builder
    {
        return $query
            ->where('type', TransactionType::OUTGOING)
            ->whereNull('block_number')
            ->whereNull('dropped_at');
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
