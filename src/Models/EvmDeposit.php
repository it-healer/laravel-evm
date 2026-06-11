<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelEvm\Casts\BigDecimalCast;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmDeposit extends Model
{
    protected $fillable = [
        'network_id',
        'wallet_id',
        'address_id',
        'token_id',
        'txid',
        'amount',
        'block_number',
        'confirmations',
        'time_at',
    ];

    protected $appends = [
        'symbol',
    ];

    protected function casts(): array
    {
        return [
            'amount' => BigDecimalCast::class,
            'block_number' => 'integer',
            'confirmations' => 'integer',
            'time_at' => 'datetime',
        ];
    }

    public function network(): BelongsTo
    {
        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $this->belongsTo($model, 'network_id');
    }

    public function wallet(): BelongsTo
    {
        /** @var class-string<EvmWallet> $model */
        $model = Evm::getModel(EvmModel::Wallet);

        return $this->belongsTo($model, 'wallet_id');
    }

    public function address(): BelongsTo
    {
        /** @var class-string<EvmAddress> $model */
        $model = Evm::getModel(EvmModel::Address);

        return $this->belongsTo($model, 'address_id');
    }

    public function token(): BelongsTo
    {
        /** @var class-string<EvmToken> $model */
        $model = Evm::getModel(EvmModel::Token);

        return $this->belongsTo($model, 'token_id');
    }

    protected function symbol(): Attribute
    {
        return new Attribute(
            get: fn () => $this->token_id
                ? ($this->token?->symbol ?: 'TOKEN')
                : ($this->network?->currency_symbol ?: 'ETH')
        );
    }
}
