<?php

namespace ItHealer\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Explorer\Contracts\ExplorerDriverInterface;
use ItHealer\LaravelEvm\Explorer\ExplorerManager;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\Concerns\TracksComputeUnits;

class EvmExplorer extends Model
{
    use TracksComputeUnits;

    protected ?ExplorerDriverInterface $_api = null;

    protected $fillable = [
        'network_id',
        'driver',
        'name',
        'title',
        'base_url',
        'api_key',
        'proxy',
        'sync_at',
        'sync_data',
        'requests',
        'requests_at',
        'credits',
        'credits_at',
        'worked',
        'available',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'driver' => ExplorerDriver::class,
            'sync_at' => 'datetime',
            'sync_data' => 'array',
            'requests_at' => 'date',
            'credits' => 'integer',
            'credits_at' => 'datetime',
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

    public function api(): ExplorerDriverInterface
    {
        if (!$this->_api) {
            $this->_api = ExplorerManager::make($this);
        }

        return $this->_api;
    }
}
