<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Brick\Math\BigDecimal;
use ItHealer\LaravelEvm\Casts\BigDecimalCast;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Services\PendingBalance;

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
        'available_balance',
        'available_tokens_balances',
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

    /**
     * Native balance minus broadcast-but-unconfirmed outgoing transfers (amount + fees).
     * This is the amount actually spendable right now; it drops the moment a withdrawal
     * is sent, before the chain reflects it, so the user never sees money that is gone.
     */
    protected function availableBalance(): Attribute
    {
        return new Attribute(
            get: function (): string {
                $pending = PendingBalance::forAddress($this->network_id, (string) $this->address?->address);

                return (string) PendingBalance::availableNative(
                    BigDecimal::of($this->balance ?? 0),
                    $pending
                );
            }
        );
    }

    /**
     * Token balances (same shape as tokens_balances) reduced by pending outgoing token transfers.
     */
    protected function availableTokensBalances(): Attribute
    {
        /** @var class-string<EvmToken> $model */
        $model = Evm::getModel(EvmModel::Token);

        return new Attribute(
            get: function () use ($model) {
                $pending = PendingBalance::forAddress($this->network_id, (string) $this->address?->address);

                return $model::query()
                    ->where('network_id', $this->network_id)
                    ->get()
                    ->map(fn (Model $token) => [
                        ...$token->only(['address', 'name', 'symbol', 'decimals']),
                        'balance' => ($this->tokens[$token->address] ?? null) !== null
                            ? (string) PendingBalance::availableToken($token->address, $this->tokens[$token->address], $pending)
                            : null,
                    ])
                    ->keyBy('address');
            }
        );
    }
}
