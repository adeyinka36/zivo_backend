<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Collection;

class CampaignDiscoveryService
{
    /**
     * Returns up to `$limit` campaigns in random order, or every campaign if the table has fewer.
     * No filters on active state, payment, or schedule.
     *
     * @param int $limit Maximum rows (capped at 50).
     */
    public function discover(int $limit = 20): Collection
    {
        $limit = max(1, min(50, $limit));

        return Campaign::query()
            ->has('media')
            ->with(['media.creator', 'media.locations'])
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }
}
