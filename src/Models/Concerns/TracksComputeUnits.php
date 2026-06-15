<?php

namespace ItHealer\LaravelEvm\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Meters Compute Units (CU) spent on a node/explorer in a `credits` counter that resets
 * at the start of every calendar month. Selection orders by least credits this month, so
 * load is distributed across several nodes/explorers automatically.
 *
 * @property int $credits
 * @property \Illuminate\Support\Carbon|null $credits_at
 */
trait TracksComputeUnits
{
    public function recordCredits(int $credits): void
    {
        if ($credits <= 0 || !$this->exists) {
            return;
        }

        $now = Date::now();

        if (!$this->credits_at || !$this->credits_at->isSameMonth($now)) {
            $this->forceFill(['credits' => $credits, 'credits_at' => $now])->saveQuietly();

            return;
        }

        $this->increment('credits', $credits, ['credits_at' => $now]);
    }

    /**
     * Credits spent in the current month (a stale month counts as zero).
     */
    public function creditsThisMonth(): int
    {
        if (!$this->credits_at || !$this->credits_at->isSameMonth(Date::now())) {
            return 0;
        }

        return (int)$this->credits;
    }

    public function scopeOrderByCredits(Builder $query): Builder
    {
        return $query->orderByRaw(
            'case when credits_at is null or credits_at < ? then 0 else credits end asc',
            [Date::now()->startOfMonth()->toDateTimeString()]
        );
    }
}
