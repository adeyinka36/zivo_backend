<?php

namespace App\Services\Creator;

use App\Models\Creator;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CreatorAccountDeletionService
{
    public function __construct(
        private readonly MediaService $mediaService
    ) {}

    public function delete(Creator $creator): void
    {
        DB::transaction(function () use ($creator): void {
            $mediaItems = Media::query()
                ->where('creator_id', $creator->id)
                ->get();

            foreach ($mediaItems as $media) {
                $this->mediaService->delete($media);
            }

            $this->deleteProofOfAddress($creator);

            $creator->tokens()->delete();
            $creator->forceDelete();
        });
    }

    private function deleteProofOfAddress(Creator $creator): void
    {
        $path = $creator->proof_of_address_path;
        if (! is_string($path) || $path === '') {
            return;
        }

        $disk = app()->environment('testing') ? 'public' : 's3';

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to delete creator proof of address', [
                'creator_id' => $creator->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
