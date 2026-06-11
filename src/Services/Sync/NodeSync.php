<?php

namespace ItHealer\LaravelEvm\Services\Sync;

use Illuminate\Support\Facades\Date;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Services\BaseSync;

class NodeSync extends BaseSync
{
    public function __construct(protected EvmNode $node)
    {
    }

    public function run(): void
    {
        parent::run();

        $this
            ->resetRequests()
            ->syncBlock();
    }

    protected function resetRequests(): self
    {
        if (is_null($this->node->requests_at) || !$this->node->requests_at->isToday()) {
            $this->node->update([
                'requests' => 0,
                'requests_at' => Date::now(),
            ]);

            $this->log('Requests counter successfully reset.');
        }

        return $this;
    }

    protected function syncBlock(): self
    {
        try {
            $blockNumber = $this->node->api()->getLatestBlockNumber();
        } catch (\Exception $e) {
            $this->node->update([
                'worked' => false,
            ]);

            throw $e;
        }

        $this->node->increment('requests');
        $this->node->update([
            'sync_at' => Date::now(),
            'block_number' => $blockNumber,
            'worked' => true,
        ]);

        return $this;
    }
}
