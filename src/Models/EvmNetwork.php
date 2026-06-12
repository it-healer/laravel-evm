<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Enums\TxType;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmNetwork extends Model
{
    protected $fillable = [
        'chain_id',
        'name',
        'title',
        'currency_symbol',
        'currency_decimals',
        'explorer_url',
        'tx_type',
        'confirmations_target',
        'lag_blocks',
        'block_time',
        'active',
        'sync_at',
        'sync_data',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'currency_decimals' => 'integer',
            'tx_type' => TxType::class,
            'confirmations_target' => 'integer',
            'lag_blocks' => 'integer',
            'block_time' => 'integer',
            'active' => 'boolean',
            'sync_at' => 'datetime',
            'sync_data' => 'array',
        ];
    }

    public function nodes(): HasMany
    {
        /** @var class-string<EvmNode> $model */
        $model = Evm::getModel(EvmModel::Node);

        return $this->hasMany($model, 'network_id');
    }

    /**
     * Wallets that have this network attached.
     */
    public function wallets(): BelongsToMany
    {
        /** @var class-string<EvmWallet> $model */
        $model = Evm::getModel(EvmModel::Wallet);

        return $this->belongsToMany($model, 'evm_wallet_networks', 'network_id', 'wallet_id')
            ->withTimestamps();
    }

    public function explorers(): HasMany
    {
        /** @var class-string<EvmExplorer> $model */
        $model = Evm::getModel(EvmModel::Explorer);

        return $this->hasMany($model, 'network_id');
    }

    public function tokens(): HasMany
    {
        /** @var class-string<EvmToken> $model */
        $model = Evm::getModel(EvmModel::Token);

        return $this->hasMany($model, 'network_id');
    }

    public function transactions(): HasMany
    {
        /** @var class-string<EvmTransaction> $model */
        $model = Evm::getModel(EvmModel::Transaction);

        return $this->hasMany($model, 'network_id');
    }

    public function deposits(): HasMany
    {
        /** @var class-string<EvmDeposit> $model */
        $model = Evm::getModel(EvmModel::Deposit);

        return $this->hasMany($model, 'network_id');
    }

    public function effectiveLagBlocks(): int
    {
        return $this->lag_blocks ?? (int)config('evm.sync.lag_blocks', 20);
    }
}
