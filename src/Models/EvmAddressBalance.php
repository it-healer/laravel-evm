<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelEvm\Casts\BigDecimalCast;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmAddressBalance extends Model
{
    protected $fillable = [
        'address_id',
        'network_id',
        'balance',
        'tokens',
        'sync_block_number',
        'sync_at',
    ];

    protected $appends = [
        'tokens_balances',
    ];

    protected $hidden = [
        'tokens',
    ];

    protected function casts(): array
    {
        return [
            'balance' => BigDecimalCast::class,
            'tokens' => 'array',
            'sync_block_number' => 'integer',
            'sync_at' => 'datetime',
        ];
    }

    public function address(): BelongsTo
    {
        /** @var class-string<EvmAddress> $model */
        $model = Evm::getModel(EvmModel::Address);

        return $this->belongsTo($model, 'address_id');
    }

    public function network(): BelongsTo
    {
        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $this->belongsTo($model, 'network_id');
    }

    /**
     * Token balances hydrated with token metadata of this row's network.
     */
    protected function tokensBalances(): Attribute
    {
        /** @var class-string<EvmToken> $model */
        $model = Evm::getModel(EvmModel::Token);

        return new Attribute(
            get: fn () => $model::query()
                ->where('network_id', $this->network_id)
                ->get()
                ->map(fn (Model $token) => [
                    ...$token->only(['address', 'name', 'symbol', 'decimals']),
                    'balance' => $this->tokens[$token->address] ?? null,
                ])
                ->keyBy('address')
        );
    }
}
