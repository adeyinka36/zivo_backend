<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Services\LocationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MediaService
{
    public function __construct(
        private readonly ThumbnailService $thumbnailService,
        private readonly LocationService $locationService
    ) {}

    /**
     * Store a new media file.
     *
     * @param UploadedFile $file
     * @param array $metadata
     * @param int $userId
     * @return Media
     */
    public function store(UploadedFile $file, array $metadata, string $userId): Media
    {
        $disk = app()->environment('testing') ? 'public' : 's3';
        $path = $disk === 's3' ? 'zivo_media/' . $file->hashName() : 'media/' . $file->hashName();

        $file->storeAs(
            $disk === 's3' ? 'zivo_media' : 'media',
            $file->hashName(),
            ['disk' => $disk]
        );

        $media = Media::create([
            'user_id' => $userId,
            'name' => $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'disk' => $disk,
            'description' => $metadata['description'] ?? null,
            'reward' => $metadata['reward'] ?? 100,
            'rounds' => $metadata['rounds'] ?? 1,
        ]);



        return $media;
    }

    /**
     * Upload file for an existing draft media record.
     *
     * @param UploadedFile $file
     * @param Media $draft
     * @return Media
     */
    public function uploadFile(UploadedFile $file, Media $draft): Media
    {
        $disk = app()->environment('testing') ? 'public' : 's3';
        $path = $disk === 's3' ? 'zivo_media/' . $file->hashName() : 'media/' . $file->hashName();

        $file->storeAs(
            $disk === 's3' ? 'zivo_media' : 'media',
            $file->hashName(),
            ['disk' => $disk]
        );

        // Update the draft with file information
        $draft->update([
            'name' => $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'disk' => $disk,
        ]);

        return $draft;
    }

    /**
     * Delete a media file.
     *
     * @param Media $media
     * @return bool
     */
    public function delete(Media $media): bool
    {
        // Skip storage deletion for external URLs (disk = 'url')
        // These are external files not managed by our storage system
        if ($media->disk !== 'url') {
            try {
                Storage::disk($media->disk)->delete($media->path);
            } catch (\Exception $e) {
                Log::warning("Failed to delete file from storage", [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $media->path,
                    'error' => $e->getMessage()
                ]);
                // Continue with database deletion even if storage deletion fails
            }
        } else {
            Log::info("Skipping storage deletion for external URL", [
                'media_id' => $media->id,
                'path' => $media->path
            ]);
        }

        return $media->delete();
    }


    private function isVideoFile(UploadedFile $file): bool
    {
        $videoMimeTypes = [
            'video/mp4',
            'video/avi',
            'video/mov',
            'video/wmv',
            'video/flv',
            'video/webm',
            'video/mkv'
        ];
        
        return in_array($file->getMimeType(), $videoMimeTypes);
    }

    /**
     * Create a draft media record with metadata only
     */
    public function createDraftMedia(array $data, User $user): Media
    {
        $targetViews = (int) config('services.media.target_views', 10);

        return Media::create([
            'user_id' => $user->id,
            'name' => 'Pending Upload',
            'file_name' => 'pending',
            'mime_type' => 'pending',
            'size' => 0,
            'path' => 'pending',
            'disk' => 'pending',
            'description' => $data['description'] ?? null,
            'location_selections' => !empty($data['location_selections']) ? $data['location_selections'] : null,
            'reward' => $data['reward'],
            'rounds' => $data['rounds'],
            'quiz_number' => rand(1, $targetViews),
            'payment_status' => 'pending',
        ]);
    }


    /**
     * Create questions for media
     */
    public function createQuestions(Media $media, array $questions): void
    {
        if ($media->questions()->exists()) {
            return;
        }

        foreach ($questions as $questionData) {
            $answerWord = strtolower(trim($questionData['answer']));
            $options = $this->generateOptions($answerWord);
            
            $media->questions()->create([
                'question' => $questionData['question'],
                'answer' => $options['correct_option'],
                'option_a' => $options['options'][0],
                'option_b' => $options['options'][1],
                'option_c' => $options['options'][2],
                'option_d' => $options['options'][3],
            ]);
        }

        if (empty($questions)) {
            $options = $this->generateOptions('zivo');
            $media->questions()->create([
                'question' => 'What is this platform?',
                'answer' => $options['correct_option'],
                'option_a' => $options['options'][0],
                'option_b' => $options['options'][1],
                'option_c' => $options['options'][2],
                'option_d' => $options['options'][3],
            ]);
        }
    }

    /**
     * Generate multiple choice options from answer word
     *
     * @param string $answerWord
     * @return array{options: array<int, string>, correct_option: string}
     */
    private function generateOptions(string $answerWord): array
    {
        $wordList = config('quiz.word_list', []);
        $availableWords = array_filter($wordList, fn($word) => strtolower($word) !== strtolower($answerWord));
        
        if (count($availableWords) < 3) {
            $availableWords = array_merge($availableWords, ['apple', 'banana', 'orange', 'grape', 'strawberry']);
            $availableWords = array_filter($availableWords, fn($word) => strtolower($word) !== strtolower($answerWord));
        }
        
        $randomWords = array_values(array_intersect_key($availableWords, array_flip(array_rand($availableWords, 3))));
        $allOptions = array_merge([$answerWord], $randomWords);
        shuffle($allOptions);
        
        $correctOption = 'A';
        foreach (['A', 'B', 'C', 'D'] as $index => $option) {
            if (strtolower($allOptions[$index]) === strtolower($answerWord)) {
                $correctOption = $option;
                break;
            }
        }
        
        return [
            'options' => $allOptions,
            'correct_option' => $correctOption,
        ];
    }
}
