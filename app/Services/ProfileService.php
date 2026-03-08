<?php

namespace App\Services;

use App\Models\User;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileService
{
    public function getProfileWithMedia(User $user): array
    {
        // Users no longer create media - return empty collection
        $media = collect([]);

        return [
            'user' => $user,
            'media' => $media,
        ];
    }

    public function updateProfile(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user->update($data);
            
            Log::info('Profile updated', [
                'user_id' => $user->id,
                'updated_fields' => array_keys($data),
            ]);
            
            return $user->fresh();
        });
    }

    public function getUserMedia(User $user, int $perPage = 20, int $page = 1)
    {
        return Media::whereRaw('1 = 0')->paginate($perPage, ['*'], 'page', $page);
    }
} 