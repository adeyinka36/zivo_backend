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
     * Removes temp upload file (local), main asset, and thumbnail when present,
     * then deletes the model (DB cascades remove campaigns, payments, assignments, etc.).
     *
     * @param Media $media
     * @return bool
     */
    public function delete(Media $media): bool
    {
        $this->deleteStoredBinaryAssets($media);

        return $media->delete();
    }

    /**
     * Best-effort removal of files backing this media record (never blocks DB delete).
     */
    private function deleteStoredBinaryAssets(Media $media): void
    {
        if ($media->temp_file_path && $media->temp_file_path !== '') {
            try {
                if (Storage::disk('local')->exists($media->temp_file_path)) {
                    Storage::disk('local')->delete($media->temp_file_path);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete temp upload file from local storage', [
                    'media_id' => $media->id,
                    'temp_file_path' => $media->temp_file_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($media->disk === 'url') {
            Log::info('Skipping storage deletion for external URL', [
                'media_id' => $media->id,
                'path' => $media->path,
            ]);

            return;
        }

        if ($media->path && $media->path !== 'pending' && $media->disk !== 'pending') {
            try {
                Storage::disk($media->disk)->delete($media->path);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete main file from storage', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $media->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($media->thumbnail && $media->disk !== 'pending') {
            try {
                Storage::disk($media->disk)->delete($media->thumbnail);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete thumbnail from storage', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'thumbnail' => $media->thumbnail,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
     * Create questions for media.
     *
     * When a question includes valid 'options' (exactly 3 strings, all different from the answer),
     * those are the 3 wrong options; the correct answer plus these 3 form the 4 choices.
     * Otherwise the 3 wrong options are generated via generateOptions().
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
                        'options' => $this->normalizeUserOptions($questionData['options'] ?? null),
                    ];
                }
            }
        }

        if (!empty($validQuestions)) {
            foreach ($validQuestions as $questionData) {
                $answerWord = $questionData['answer'];
                $userOptions = $questionData['options'];

                if ($userOptions !== null) {
                    $row = $this->buildQuestionFromUserOptions(
                        $questionData['question'],
                        $answerWord,
                        $userOptions
                    );
                } else {
                    $generated = $this->generateOptions(strtolower($answerWord));
                    $row = [
                        'question' => $questionData['question'],
                        'answer' => $generated['correct_option'],
                        'option_a' => $generated['options'][0],
                        'option_b' => $generated['options'][1],
                        'option_c' => $generated['options'][2],
                        'option_d' => $generated['options'][3],
                    ];
                }

                $media->questions()->create($row);
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
     * Normalize user-provided options: return array of 3 trimmed strings if valid, else null.
     *
     * @param mixed $options
     * @return array<int, string>|null
     */
    private function normalizeUserOptions(mixed $options): ?array
    {
        if (!is_array($options) || count($options) !== 3) {
            return null;
        }
        $trimmed = array_map(fn ($o) => trim((string) $o), $options);
        foreach ($trimmed as $o) {
            if ($o === '') {
                return null;
            }
        }
        return $trimmed;
    }

    /**
     * Build question row using 3 user-provided wrong options plus the correct answer.
     *
     * @param string $questionText
     * @param string $answerText
     * @param array<int, string> $userOptions The 3 wrong options (distractors)
     * @return array{question: string, answer: string, option_a: string, option_b: string, option_c: string, option_d: string}
     */
    private function buildQuestionFromUserOptions(string $questionText, string $answerText, array $userOptions): array
    {
        $answerLower = strtolower($answerText);
        $userLower = array_map('strtolower', $userOptions);
        if (in_array($answerLower, $userLower, true)) {
            return $this->buildQuestionFromGenerated($questionText, $answerText);
        }

        $allFour = array_merge([$answerText], $userOptions);
        shuffle($allFour);

        $optionLetters = ['A', 'B', 'C', 'D'];
        $idx = null;
        foreach ($allFour as $i => $opt) {
            if (strcasecmp($opt, $answerText) === 0) {
                $idx = $i;
                break;
            }
        }
        $correctLetter = ($idx !== null && isset($optionLetters[$idx])) ? $optionLetters[$idx] : 'A';

        return [
            'question' => $questionText,
            'answer' => $correctLetter,
            'option_a' => $allFour[0],
            'option_b' => $allFour[1],
            'option_c' => $allFour[2],
            'option_d' => $allFour[3],
        ];
    }

    /**
     * Fallback when user options are invalid: use full generation.
     *
     * @param string $questionText
     * @param string $answerText
     * @return array{question: string, answer: string, option_a: string, option_b: string, option_c: string, option_d: string}
     */
    private function buildQuestionFromGenerated(string $questionText, string $answerText): array
    {
        $generated = $this->generateOptions(strtolower($answerText));
        return [
            'question' => $questionText,
            'answer' => $generated['correct_option'],
            'option_a' => $generated['options'][0],
            'option_b' => $generated['options'][1],
            'option_c' => $generated['options'][2],
            'option_d' => $generated['options'][3],
        ];
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
