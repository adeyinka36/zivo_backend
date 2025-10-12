<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ThumbnailService
{
    public function generateThumbnail(UploadedFile $videoFile, string $videoPath): ?string
    {
        try {
            if (!$this->isVideoFile($videoFile)) {
                return null;
            }

            $tempVideoPath = $videoFile->getRealPath();
            $thumbnailPath = $this->getThumbnailPath($videoPath);
            
            $success = $this->extractFrame($tempVideoPath, $thumbnailPath);
            
            if ($success) {
                return $thumbnailPath;
            }
            
            return $this->generateFallbackThumbnail($videoFile, $thumbnailPath);
            
        } catch (\Exception $e) {
            Log::error('Thumbnail generation failed', [
                'file' => $videoFile->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function generateThumbnailFromPath(string $videoFilePath, string $videoPath): ?string
    {
        try {
            $thumbnailPath = $this->getThumbnailPath($videoPath);
            
            $success = $this->extractFrame($videoFilePath, $thumbnailPath);
            
            if ($success) {
                return $thumbnailPath;
            }
            
            return $this->generateFallbackThumbnailFromPath($videoFilePath, $thumbnailPath);
            
        } catch (\Exception $e) {
            Log::error('Thumbnail generation from path failed', [
                'video_path' => $videoPath,
                'error' => $e->getMessage()
            ]);
            return null;
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

    private function getThumbnailPath(string $videoPath): string
    {
        $pathInfo = pathinfo($videoPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return $directory . '/thumbnails/' . $filename . '_thumb.jpg';
    }

    private function extractFrame(string $videoPath, string $thumbnailPath): bool
    {
        $disk = app()->environment('testing') ? 'public' : 's3';
        $tempThumbnailPath = storage_path('app/temp_' . Str::random(10) . '.jpg');
        
        $command = sprintf(
            '/usr/bin/ffmpeg -i %s -ss 5 -vframes 1 -q:v 2 -update 1 -y %s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($tempThumbnailPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempThumbnailPath)) {
            $thumbnailContent = file_get_contents($tempThumbnailPath);
            unlink($tempThumbnailPath);
            
            Storage::disk($disk)->put($thumbnailPath, $thumbnailContent);
            Log::info('Thumbnail extracted successfully', [
                'video_path' => $videoPath,
                'thumbnail_path' => $thumbnailPath,
                'command' => $command
            ]);
            return true;
        }
        
        Log::error('FFmpeg thumbnail extraction failed', [
            'video_path' => $videoPath,
            'thumbnail_path' => $thumbnailPath,
            'command' => $command,
            'return_code' => $returnCode,
            'output' => implode("\n", $output),
            'temp_file_exists' => file_exists($tempThumbnailPath)
        ]);
        
        if (file_exists($tempThumbnailPath)) {
            unlink($tempThumbnailPath);
        }
        
        return false;
    }

    private function generateFallbackThumbnail(UploadedFile $videoFile, string $thumbnailPath): ?string
    {
        try {
            $disk = app()->environment('testing') ? 'public' : 's3';
            $tempThumbnailPath = storage_path('app/temp_' . Str::random(10) . '.jpg');
            
            $command = sprintf(
                '/usr/bin/ffmpeg -i %s -ss 1 -vframes 1 -q:v 2 -update 1 -y %s 2>&1',
                escapeshellarg($videoFile->getRealPath()),
                escapeshellarg($tempThumbnailPath)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($tempThumbnailPath)) {
                $thumbnailContent = file_get_contents($tempThumbnailPath);
                unlink($tempThumbnailPath);
                
                Storage::disk($disk)->put($thumbnailPath, $thumbnailContent);
                return $thumbnailPath;
            }
            
            if (file_exists($tempThumbnailPath)) {
                unlink($tempThumbnailPath);
            }
            
        } catch (\Exception $e) {
            Log::error('Fallback thumbnail generation failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    private function generateFallbackThumbnailFromPath(string $videoFilePath, string $thumbnailPath): ?string
    {
        try {
            $disk = app()->environment('testing') ? 'public' : 's3';
            $tempThumbnailPath = storage_path('app/temp_' . Str::random(10) . '.jpg');
            
            $command = sprintf(
                '/usr/bin/ffmpeg -i %s -ss 1 -vframes 1 -q:v 2 -update 1 -y %s 2>&1',
                escapeshellarg($videoFilePath),
                escapeshellarg($tempThumbnailPath)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($tempThumbnailPath)) {
                $thumbnailContent = file_get_contents($tempThumbnailPath);
                unlink($tempThumbnailPath);
                
                Storage::disk($disk)->put($thumbnailPath, $thumbnailContent);
                return $thumbnailPath;
            }
            
            if (file_exists($tempThumbnailPath)) {
                unlink($tempThumbnailPath);
            }
            
        } catch (\Exception $e) {
            Log::error('Fallback thumbnail generation from path failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
}
