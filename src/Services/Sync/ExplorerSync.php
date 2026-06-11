<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Illuminate\Support\Facades\Date;
use ItHealer\LaravelEvm\Models\EvmExplorer;
use ItHealer\LaravelEvm\Services\BaseSync;

class ExplorerSync extends BaseSync
{
    public function __construct(protected EvmExplorer $explorer)
    {
    }

    public function run(): void
    {
        parent::run();

        $this
            ->resetRequests()
            ->healthCheck();
    }

    protected function resetRequests(): self
    {
        if (is_null($this->explorer->requests_at) || !$this->explorer->requests_at->isToday()) {
            $this->explorer->update([
                'requests' => 0,
                'requests_at' => Date::now(),
            ]);

            $this->log('Requests counter successfully reset.');
        }

        return $this;
    }

    protected function healthCheck(): self
    {
        if (!$this->explorer->api()->healthCheck()) {
            $this->explorer->update([
                'worked' => false,
            ]);

            throw new \RuntimeException("Explorer {$this->explorer->name} health check failed.");
        }

        $this->explorer->increment('requests');
        $this->explorer->update([
            'sync_at' => Date::now(),
            'worked' => true,
        ]);

        return $this;
    }
}
