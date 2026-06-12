<?php

namespace ItHealer\LaravelEvm\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use ItHealer\LaravelEvm\Casts\EncryptedCast;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmWallet extends Model
{
    protected static array $plainPasswords = [];

    protected $fillable = [
        'name',
        'title',
        'password',
        'mnemonic',
        'seed',
        'derivation_path',
        'sync_at',
    ];

    protected $appends = [
        'has_password',
        'has_mnemonic',
        'has_seed',
    ];

    protected $hidden = [
        'password',
        'mnemonic',
        'seed',
    ];

    protected function casts(): array
    {
        return [
            'sync_at' => 'datetime',
            'password' => 'encrypted',
            'mnemonic' => EncryptedCast::class,
            'seed' => EncryptedCast::class,
        ];
    }

    public function unlockWallet(?string $password): void
    {
        self::$plainPasswords[$this->name] = $password;
    }

    public function getPlainPasswordAttribute(): ?string
    {
        return self::$plainPasswords[$this->name] ?? null;
    }

    /**
     * Networks attached to this wallet: only they are shown and synchronized.
     */
    public function networks(): BelongsToMany
    {
        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $this->belongsToMany($model, 'evm_wallet_networks', 'wallet_id', 'network_id')
            ->withTimestamps();
    }

    public function addresses(): HasMany
    {
        /** @var class-string<EvmAddress> $model */
        $model = Evm::getModel(EvmModel::Address);

        return $this->hasMany($model, 'wallet_id');
    }

    public function balances(): HasManyThrough
    {
        /** @var class-string<EvmAddressBalance> $balanceModel */
        $balanceModel = Evm::getModel(EvmModel::AddressBalance);

        /** @var class-string<EvmAddress> $addressModel */
        $addressModel = Evm::getModel(EvmModel::Address);

        return $this->hasManyThrough($balanceModel, $addressModel, 'wallet_id', 'address_id');
    }

    public function deposits(): HasMany
    {
        /** @var class-string<EvmDeposit> $model */
        $model = Evm::getModel(EvmModel::Deposit);

        return $this->hasMany($model, 'wallet_id');
    }

    public function transactions(): HasManyThrough
    {
        /** @var class-string<EvmTransaction> $transactionModel */
        $transactionModel = Evm::getModel(EvmModel::Transaction);

        /** @var class-string<EvmAddress> $addressModel */
        $addressModel = Evm::getModel(EvmModel::Address);

        return $this->hasManyThrough(
            $transactionModel,
            $addressModel,
            'wallet_id',
            'address',
            'id',
            'address'
        );
    }

    /**
     * Total native coin balance of all wallet addresses in the given network.
     */
    public function balanceForNetwork(EvmNetwork|int $network): BigDecimal
    {
        $networkId = $network instanceof EvmNetwork ? $network->id : $network;

        return $this->balances()
            ->where('network_id', $networkId)
            ->get()
            ->reduce(
                fn (BigDecimal $carry, EvmAddressBalance $balance) => $carry->plus($balance->balance ?? 0),
                BigDecimal::zero()
            );
    }

    /**
     * Aggregated token balances of all wallet addresses in the given network.
     *
     * @return array<string, BigDecimal> map of contract address => balance
     */
    public function tokensForNetwork(EvmNetwork|int $network): array
    {
        $networkId = $network instanceof EvmNetwork ? $network->id : $network;

        $totals = [];
        foreach ($this->balances()->where('network_id', $networkId)->get() as $balance) {
            foreach ($balance->tokens ?? [] as $contract => $amount) {
                $totals[$contract] = ($totals[$contract] ?? BigDecimal::zero())->plus($amount);
            }
        }

        return $totals;
    }

    public function getHasPasswordAttribute(): bool
    {
        return !!$this->password;
    }

    public function getHasMnemonicAttribute(): bool
    {
        return !!$this->mnemonic;
    }

    public function getHasSeedAttribute(): bool
    {
        return !!$this->seed;
    }
}
