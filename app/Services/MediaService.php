<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Creator;
use App\Models\Location;
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
     * @param string $creatorId
     * @return Media
     */
    public function store(UploadedFile $file, array $metadata, string $creatorId): Media
    {
        $disk = app()->environment('testing') ? 'public' : 's3';
        $path = $disk === 's3' ? 'zivo_media/' . $file->hashName() : 'media/' . $file->hashName();

        $file->storeAs(
            $disk === 's3' ? 'zivo_media' : 'media',
            $file->hashName(),
            ['disk' => $disk]
        );

        $media = Media::create([
            'creator_id' => $creatorId,
            'name' => $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'disk' => $disk,
            'description' => $metadata['description'] ?? null,
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
     * Create a new draft media record with metadata.
     *
     * Always creates a new media record so the same file can be uploaded multiple times
     * (e.g. for different campaigns). No draft reuse - each "Pay & Upload" is a fresh flow.
     */
    public function createDraftMedia(array $data, Creator $creator, ?UploadedFile $file = null): Media
    {
        $media = Media::create([
            'creator_id' => $creator->id,
            'name' => 'Pending Upload',
            'file_name' => 'pending',
            'mime_type' => 'pending',
            'size' => 0,
            'path' => 'pending',
            'disk' => 'pending',
            'offer' => $data['offer'] ?? null,
            'rounds' => $data['rounds'],
            'from_date' => isset($data['from_date']) ? $data['from_date'] : null,
            'to_date' => isset($data['to_date']) ? $data['to_date'] : null,
            'payment_status' => 'pending',
        ]);

        if (!empty($data['location_id'])) {
            $media->locations()->attach($data['location_id']);
        }

        return $media;
    }


    /**
     * Create questions for media
     */
    public function createQuestions(Media $media, ?array $questions = null): void
    {
        if ($media->questions()->exists()) {
            return;
        }

        $validQuestions = [];
        if (!empty($questions) && is_array($questions)) {
            foreach ($questions as $questionData) {
                if (
                    isset($questionData['question']) && 
                    isset($questionData['answer']) &&
                    !empty(trim($questionData['question'])) &&
                    !empty(trim($questionData['answer']))
                ) {
                    $validQuestions[] = [
                        'question' => trim($questionData['question']),
                        'answer' => trim($questionData['answer']),
                    ];
                }
            }
        }

        if (!empty($validQuestions)) {
            foreach ($validQuestions as $questionData) {
                $answerWord = strtolower($questionData['answer']);
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
        } else {
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
        $answerWords = preg_split('/\s+/', trim($answerWord));
        $isMultiWord = count($answerWords) > 1;
        
        if ($isMultiWord) {
            return $this->generateMultiWordOptions($answerWord, $answerWords, $wordList);
        }
        
        return $this->generateSingleWordOptions($answerWord, $wordList);
    }

    /**
     * Generate options for single-word answers
     *
     * @param string $answerWord
     * @param array $wordList
     * @return array{options: array<int, string>, correct_option: string}
     */
    private function generateSingleWordOptions(string $answerWord, array $wordList): array
    {
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

    /**
     * Generate options for multi-word answers by replacing random words
     *
     * @param string $originalAnswer
     * @param array $answerWords
     * @param array $wordList
     * @return array{options: array<int, string>, correct_option: string}
     */
    private function generateMultiWordOptions(string $originalAnswer, array $answerWords, array $wordList): array
    {
        $wordCount = count($answerWords);
        $maxReplacements = min(3, $wordCount);
        
        $availableWords = array_filter($wordList, function($word) use ($answerWords) {
            $wordLower = strtolower($word);
            foreach ($answerWords as $answerWord) {
                if (strtolower($answerWord) === $wordLower) {
                    return false;
                }
            }
            return true;
        });
        
        if (count($availableWords) < 10) {
            $fallbackWords = ['apple', 'banana', 'orange', 'grape', 'strawberry', 'mango', 'peach', 'cherry', 'kiwi', 'lemon'];
            $availableWords = array_merge($availableWords, $fallbackWords);
            $availableWords = array_filter($availableWords, function($word) use ($answerWords) {
                $wordLower = strtolower($word);
                foreach ($answerWords as $answerWord) {
                    if (strtolower($answerWord) === $wordLower) {
                        return false;
                    }
                }
                return true;
            });
        }
        
        $availableWords = array_values($availableWords);
        $allOptions = [$originalAnswer];
        $usedOptions = [strtolower($originalAnswer)];
        
        for ($i = 0; $i < 3; $i++) {
            $option = $this->generateUniqueMultiWordOption(
                $answerWords,
                $availableWords,
                $maxReplacements,
                $usedOptions
            );
            $allOptions[] = $option;
            $usedOptions[] = strtolower($option);
        }
        
        shuffle($allOptions);
        
        $correctOption = 'A';
        foreach (['A', 'B', 'C', 'D'] as $index => $option) {
            if (strtolower($allOptions[$index]) === strtolower($originalAnswer)) {
                $correctOption = $option;
                break;
            }
        }
        
        return [
            'options' => $allOptions,
            'correct_option' => $correctOption,
        ];
    }

    /**
     * Generate a unique multi-word option by replacing random words
     *
     * @param array $answerWords
     * @param array $availableWords
     * @param int $maxReplacements
     * @param array $usedOptions
     * @return string
     */
    private function generateUniqueMultiWordOption(
        array $answerWords,
        array $availableWords,
        int $maxReplacements,
        array $usedOptions
    ): string {
        $maxAttempts = 50;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $numReplacements = rand(1, $maxReplacements);
            $wordCount = count($answerWords);
            $positionsToReplace = [];
            
            if ($numReplacements >= $wordCount) {
                $positionsToReplace = range(0, $wordCount - 1);
            } else {
                $allPositions = range(0, $wordCount - 1);
                shuffle($allPositions);
                $positionsToReplace = array_slice($allPositions, 0, $numReplacements);
            }
            
            $replacementWords = [];
            $availableWordsShuffled = $availableWords;
            shuffle($availableWordsShuffled);
            
            for ($i = 0; $i < $numReplacements; $i++) {
                if (empty($availableWordsShuffled)) {
                    $availableWordsShuffled = $availableWords;
                    shuffle($availableWordsShuffled);
                }
                $replacementWords[] = array_shift($availableWordsShuffled);
            }
            
            $newOption = $answerWords;
            foreach ($positionsToReplace as $index => $position) {
                $newOption[$position] = $replacementWords[$index];
            }
            
            $optionText = implode(' ', $newOption);
            $optionTextLower = strtolower($optionText);
            
            if (!in_array($optionTextLower, $usedOptions, true)) {
                return $optionText;
            }
            
            $attempt++;
        }
        
        $fallbackOption = $answerWords;
        $fallbackOption[0] = $availableWords[array_rand($availableWords)];
        return implode(' ', $fallbackOption);
    }

    /**
     * Get or create a location record for the user's coordinates.
     *
     * @param User $user
     * @return int|null Location ID or null if coordinates are invalid
     */
    /**
     * Get location ID from coordinates (locations must be added manually to database).
     *
     * @param float $latitude
     * @param float $longitude
     * @return int|null Location ID or null if not found
     */
    private function getOrCreateLocationFromCoordinates(float $latitude, float $longitude): ?int
    {
        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        // Try to find location by coordinates
        $location = Location::where('latitude', $lat)
            ->where('longitude', $lng)
            ->first();

        if ($location) {
            return $location->id;
        }

        // Location not found - must be added manually to database
        Log::warning('Location not found in database', [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        return null;
    }
}
