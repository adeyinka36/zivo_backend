<?php

namespace App\Http\Resources;

use App\Models\CampaignCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignCode
 */
class RewardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->relationLoaded('media') ? $this->media : null;
        $creator = $media?->creator;

        $businessName = $creator?->business_name ?: 'Unknown business';
        $businessAddress = $creator?->address;
        $claimLocation = $businessAddress ? "{$businessName}, {$businessAddress}" : $businessName;
        $isExpired = $media?->to_date !== null && now()->greaterThan($media->to_date);

        $status = 'available';
        if ($this->claimed_at !== null) {
            $status = 'claimed';
        } elseif ($isExpired) {
            $status = 'expired';
        }

        return [
            'id' => $this->id,
            'media_id' => $this->media_id,
            'business_name' => $businessName,
            'offer' => $media?->offer,
            'code' => $this->code,
            'claim_location' => $claimLocation,
            'assigned_at' => optional($this->assigned_at)->toIso8601String(),
            'claimed_at' => optional($this->claimed_at)->toIso8601String(),
            'from_date' => optional($media?->from_date)->toIso8601String(),
            'to_date' => optional($media?->to_date)->toIso8601String(),
            'is_claimed' => $this->claimed_at !== null,
            'is_expired' => $isExpired,
            'status' => $status,
        ];
    }
}
