<?php

namespace App\Jobs;

use App\Models\CampaignCode;
use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateCampaignCodesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $mediaId
    ) {}

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        if (!$media) {
            Log::warning('GenerateCampaignCodesJob: Media not found', [
                'media_id' => $this->mediaId,
            ]);
            return;
        }

        $targetViewsPerRound = (int) config('services.media.target_views', 10);
        $totalCodes = (int) $media->rounds * $targetViewsPerRound;

        if ($totalCodes <= 0) {
            Log::info('GenerateCampaignCodesJob: No codes required for media', [
                'media_id' => $media->id,
                'rounds' => $media->rounds,
                'target_views_per_round' => $targetViewsPerRound,
            ]);
            return;
        }

        $existingCount = $media->codes()->count();
        $remaining = $totalCodes - $existingCount;

        if ($remaining <= 0) {
            Log::info('GenerateCampaignCodesJob: Codes already generated for media', [
                'media_id' => $media->id,
                'total_codes' => $totalCodes,
                'existing_count' => $existingCount,
            ]);
            return;
        }

        $batchSize = 500;

        while ($remaining > 0) {
            $createNow = min($batchSize, $remaining);
            $records = [];

            for ($i = 0; $i < $createNow; $i++) {
                $records[] = [
                    'id' => (string) Str::uuid(),
                    'media_id' => $media->id,
                    'code' => $this->generateCode(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            CampaignCode::insert($records);
            $remaining -= $createNow;
        }

        Log::info('GenerateCampaignCodesJob: Codes generated', [
            'media_id' => $media->id,
            'total_codes' => $totalCodes,
        ]);
    }

    private function generateCode(): string
    {
        // Generate a ZI-VO-XXXXXX style uppercase random code
        return 'ZI-VO-' . strtoupper(Str::random(6));
    }
}

