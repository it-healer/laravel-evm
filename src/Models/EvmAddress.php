<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelEvm\Casts\EncryptedCast;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmAddress extends Model
{
    protected $fillable = [
        'wallet_id',
        'address',
        'title',
        'watch_only',
        'private_key',
        'index',
        'touch_at',
        'sync_at',
        'available',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected function casts(): array
    {
        return [
            'watch_only' => 'boolean',
            'private_key' => EncryptedCast::class,
            'touch_at' => 'datetime',
            'sync_at' => 'datetime',
            'available' => 'boolean',
        ];
    }

    public function getPlainPasswordAttribute(): ?string
    {
        return $this->wallet->plain_password;
    }

    public function getPasswordAttribute(): ?string
    {
        return $this->wallet->password;
    }

    public function wallet(): BelongsTo
    {
        /** @var class-string<EvmWallet> $model */
        $model = Evm::getModel(EvmModel::Wallet);

        return $this->belongsTo($model, 'wallet_id');
    }

    public function balances(): HasMany
    {
        /** @var class-string<EvmAddressBalance> $model */
        $model = Evm::getModel(EvmModel::AddressBalance);

        return $this->hasMany($model, 'address_id');
    }

    public function deposits(): HasMany
    {
        /** @var class-string<EvmDeposit> $model */
        $model = Evm::getModel(EvmModel::Deposit);

        return $this->hasMany($model, 'address_id');
    }

    public function transactions(): HasMany
    {
        /** @var class-string<EvmTransaction> $model */
        $model = Evm::getModel(EvmModel::Transaction);

        return $this->hasMany($model, 'address', 'address');
    }

    /**
     * Per-network state (native balance, token balances, sync cursor)
     * of this address. Created lazily on first access.
     */
    public function balanceForNetwork(EvmNetwork|int $network): EvmAddressBalance
    {
        $networkId = $network instanceof EvmNetwork ? $network->id : $network;

        return $this->balances()->firstOrCreate(['network_id' => $networkId]);
    }
}
