<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

/**
 * One Alchemy Address Activity webhook per network.
 *
 * @property int $network_id
 * @property string $webhook_id
 * @property string $signing_key
 * @property string|null $auth_token
 * @property string|null $account_ref
 * @property int $addresses_count
 * @property bool $active
 */
class EvmAlchemyWebhook extends Model
{
    protected $fillable = [
        'network_id',
        'webhook_id',
        'signing_key',
        'auth_token',
        'account_ref',
        'addresses_count',
        'active',
    ];

    protected $hidden = [
        'signing_key',
        'auth_token',
    ];

    protected function casts(): array
    {
        return [
            'signing_key' => 'encrypted',
            'auth_token' => 'encrypted',
            'addresses_count' => 'integer',
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
