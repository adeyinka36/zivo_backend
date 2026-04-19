<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Collection;

class CampaignDiscoveryService
{
    /**
     * Returns up to `$limit` random active, ongoing campaigns (fewer if not enough exist).
     *
     * @param int $limit Maximum rows (capped at 50).
     */
    public function discover(int $limit = 20): Collection
    {
        $limit = max(1, min(50, $limit));

        return Campaign::query()
            ->where('active', true)
            ->has('media')
            ->whereHas('media', function ($query): void {
                $query->where('payment_status', 'paid')
                    ->where('active', true)
                    ->where('campaign_completed', false)
                    ->whereNotNull('path')
                    ->where('path', '!=', 'pending')
                    ->where(function ($q): void {
                        $q->whereNull('to_date')
                            ->orWhere('to_date', '>=', now());
                    })
                    ->where(function ($q): void {
                        $q->whereNull('from_date')
                            ->orWhere('from_date', '<=', now());
                    })
                    ->where(function ($q): void {
                        $q->whereNull('mime_type')
                            ->orWhere('mime_type', '!=', 'pending');
                    });
            })
            ->with(['media.creator', 'media.locations'])
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }
}
