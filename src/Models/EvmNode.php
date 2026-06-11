<?php

namespace ItHealer\LaravelEvm\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelEvm\Api\Node\NodeApi;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;

class EvmNode extends Model
{
    protected ?NodeApi $_api = null;

    protected $fillable = [
        'network_id',
        'name',
        'title',
        'base_url',
        'api_key',
        'proxy',
        'sync_at',
        'sync_data',
        'block_number',
        'requests',
        'requests_at',
        'worked',
        'available',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'sync_at' => 'datetime',
            'sync_data' => 'array',
            'block_number' => 'integer',
            'requests_at' => 'date',
            'worked' => 'boolean',
            'available' => 'boolean',
        ];
    }

    public function network(): BelongsTo
    {
        /** @var class-string<EvmNetwork> $model */
        $model = Evm::getModel(EvmModel::Network);

        return $this->belongsTo($model, 'network_id');
    }

    public function api(): NodeApi
    {
        if (!$this->_api) {
            $network = $this->network;

            $this->_api = new NodeApi(
                baseURL: $this->base_url,
                chainId: $network->chain_id,
                nativeDecimals: $network->currency_decimals,
                proxy: $this->proxy,
                txType: $network->tx_type,
            );
        }

        return $this->_api;
    }

    public function getLatestBlockNumber(): int
    {
        $result = $this->api()->getLatestBlockNumber();

        $this->increment('requests');

        return $result;
    }

    public function getBalance(string|EvmAddress $address): BigDecimal
    {
        if ($address instanceof EvmAddress) {
            $address = $address->address;
        }

        $result = $this->api()->getBalance($address);

        $this->increment('requests');

        return $result;
    }

    public function getBalanceOfToken(string|EvmAddress $address, string|EvmToken $contract): BigDecimal
    {
        if ($address instanceof EvmAddress) {
            $address = $address->address;
        }
        if ($contract instanceof EvmToken) {
            $contract = $contract->address;
        }

        $result = $this->api()->getBalanceOfToken($address, $contract);

        $this->increment('requests');

        return $result;
    }
}
