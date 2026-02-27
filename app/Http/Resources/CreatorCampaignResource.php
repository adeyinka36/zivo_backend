<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorCampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var Media $this */
        $targetViewsPerRound = (int) config('services.media.target_views', 10);
        $totalTargetViews = $this->rounds * $targetViewsPerRound;

        $now = now();
        $status = 'active';

        if ($this->campaign_completed) {
            $status = 'completed';
        } elseif ($this->target_reached_at) {
            $status = 'target_reached';
        } elseif ($this->to_date && $this->to_date->lt($now)) {
            $status = 'expired';
        } elseif ($this->from_date && $this->from_date->gt($now)) {
            $status = 'scheduled';
        }

        $progress = $totalTargetViews > 0
            ? min(100, (int) round(($this->view_count / $totalTargetViews) * 100))
            : 0;

        return [
            'id' => $this->id,
            'offer' => $this->offer,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
            'rounds' => $this->rounds,
            'rounds_completed' => $this->rounds_completed,
            'view_count' => $this->view_count,
            'target_views_per_round' => $targetViewsPerRound,
            'total_target_views' => $totalTargetViews,
            'campaign_completed' => (bool) $this->campaign_completed,
            'target_reached_at' => $this->target_reached_at,
            'active' => (bool) $this->active,
            'media_type' => $this->media_type,
            'file_url' => $this->getFileUrl(),
            'thumbnail_url' => $this->getThumbnailUrl(),
            'created_at' => $this->created_at,
            'status' => $status,
            'progress_percentage' => $progress,
        ];
    }
}

