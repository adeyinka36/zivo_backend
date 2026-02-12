<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\PaymentService;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Job to upload file to S3 and process media upload.
 * 
 * This job handles the asynchronous file upload to S3, then dispatches
 * ProcessMediaUploadJob to complete the media processing workflow.
 */
class UploadFileToS3Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        private readonly string $mediaId,
        private readonly string $tempFilePath,
        private readonly array $metadata,
        private readonly array $questions = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        PaymentService $paymentService
    ): void {
        try {
            $media = Media::findOrFail($this->mediaId);

            $disk = app()->environment('testing') ? 'public' : 's3';
            $fileName = basename($this->tempFilePath);
            $storagePath = $disk === 's3' ? 'zivo_media/' . $fileName : 'media/' . $fileName;

            if (!Storage::disk('local')->exists($this->tempFilePath)) {
                throw new \Exception("Temporary file not found: {$this->tempFilePath}");
            }

            $localFilePath = Storage::disk('local')->path($this->tempFilePath);

            if ($disk === 's3') {
                $this->uploadToS3WithStream($localFilePath, $storagePath, $this->metadata['mime_type']);
            } else {
                $fileContent = Storage::disk('local')->get($this->tempFilePath);
                if ($fileContent === false) {
                    throw new \Exception("Failed to read temporary file: {$this->tempFilePath}");
                }
                Storage::disk($disk)->put($storagePath, $fileContent);
            }

            if (!Storage::disk($disk)->exists($storagePath)) {
                throw new \Exception("File upload verification failed: {$storagePath}");
            }

            $expectedAmounts = $paymentService->calculateExpectedPaymentAmount($media);
            $totalAmountCents = $expectedAmounts['total_amount_cents'];

            $finalMetadata = array_merge($this->metadata, [
                'amount_paid' => $totalAmountCents / 100,
            ]);

            ProcessMediaUploadJob::dispatch(
                $media->id,
                $storagePath,
                $disk,
                $finalMetadata,
                $this->questions
            )->afterCommit();

            Storage::disk('local')->delete($this->tempFilePath);

            Log::info('File uploaded to S3 and ProcessMediaUploadJob dispatched', [
                'media_id' => $media->id,
                'storage_path' => $storagePath,
                'disk' => $disk,
            ]);

        } catch (\Exception $e) {
            Log::error('UploadFileToS3Job failed', [
                'media_id' => $this->mediaId,
                'temp_file_path' => $this->tempFilePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (Storage::disk('local')->exists($this->tempFilePath)) {
                Storage::disk('local')->delete($this->tempFilePath);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('UploadFileToS3Job failed permanently', [
            'media_id' => $this->mediaId,
            'temp_file_path' => $this->tempFilePath,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        try {
            if (Storage::disk('local')->exists($this->tempFilePath)) {
                Storage::disk('local')->delete($this->tempFilePath);
            }

            $media = Media::find($this->mediaId);
            if ($media) {
                $media->update([
                    'payment_status' => 'failed',
                    'active' => false,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to cleanup after UploadFileToS3Job failure', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upload file to S3 using streaming to avoid loading entire file into memory.
     *
     * @param string $localFilePath Full path to local file
     * @param string $s3Path S3 storage path (e.g., 'zivo_media/filename.mp4')
     * @param string $mimeType MIME type of the file
     * @throws \Exception If upload fails
     */
    private function uploadToS3WithStream(string $localFilePath, string $s3Path, string $mimeType): void
    {
        $s3Client = $this->getS3Client();
        $bucket = config('filesystems.disks.s3.bucket');

        if (!$s3Client || !$bucket) {
            throw new \Exception('S3 client or bucket not configured');
        }

        if (!file_exists($localFilePath)) {
            throw new \Exception("Local file not found: {$localFilePath}");
        }

        $fileStream = fopen($localFilePath, 'rb');
        
        if (!$fileStream) {
            throw new \Exception("Failed to open file for streaming: {$localFilePath}");
        }

        try {
            $s3Client->putObject([
                'Bucket' => $bucket,
                'Key' => $s3Path,
                'Body' => $fileStream,
                'ContentType' => $mimeType,
            ]);

            Log::info('File streamed to S3 successfully', [
                'local_path' => $localFilePath,
                's3_path' => $s3Path,
                'bucket' => $bucket,
            ]);
        } catch (\Exception $e) {
            Log::error('S3 streaming upload failed', [
                'local_path' => $localFilePath,
                's3_path' => $s3Path,
                'bucket' => $bucket,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("S3 upload failed: {$e->getMessage()}", 0, $e);
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    /**
     * Create S3 client instance from configuration.
     *
     * @return S3Client|null
     */
    private function getS3Client(): ?S3Client
    {
        try {
            $config = config('filesystems.disks.s3');
            
            if (empty($config['key']) || empty($config['secret']) || empty($config['region'])) {
                Log::error('S3 configuration incomplete', [
                    'has_key' => !empty($config['key']),
                    'has_secret' => !empty($config['secret']),
                    'has_region' => !empty($config['region']),
                ]);
                return null;
            }

            $s3Config = [
                'version' => 'latest',
                'region' => $config['region'],
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ];

            if (!empty($config['endpoint'])) {
                $s3Config['endpoint'] = $config['endpoint'];
            }

            if (!empty($config['use_path_style_endpoint'])) {
                $s3Config['use_path_style_endpoint'] = $config['use_path_style_endpoint'];
            }

            return new S3Client($s3Config);
        } catch (\Exception $e) {
            Log::error('Failed to create S3 client', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
