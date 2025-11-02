<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanedMedia extends Command
{
    protected $signature = 'media:cleanup-orphaned {--dry-run : Show what would be deleted without actually deleting}';
    protected $description = 'Remove media records where the actual files don\'t exist in storage';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No files will be deleted');
        } else {
            $this->info('🧹 CLEANUP MODE - Files will be deleted');
        }
        
        $this->newLine();

        $mediaRecords = Media::where('disk', 's3')
            ->where('payment_status', 'paid')
            ->get();

        $this->info("Found {$mediaRecords->count()} S3 media records to check...");
        $this->newLine();

        $orphanedCount = 0;
        $totalSize = 0;

        foreach ($mediaRecords as $media) {
            try {
                $exists = Storage::disk('s3')->exists($media->path);
                
                if (!$exists) {
                    $orphanedCount++;
                    $totalSize += $media->size;
                    
                    $this->warn("❌ Orphaned: {$media->file_name} (ID: {$media->id})");
                    $this->line("   Path: {$media->path}");
                    $this->line("   Size: " . number_format($media->size) . " bytes");
                    $this->line("   Created: {$media->created_at}");
                    
                    if (!$isDryRun) {
                        $media->delete();
                        $this->info("   ✅ Deleted from database");
                    }
                    
                    $this->newLine();
                }
            } catch (\Exception $e) {
                $this->error("Error checking {$media->file_name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        
        if ($orphanedCount === 0) {
            $this->info('✅ No orphaned media records found!');
        } else {
            if ($isDryRun) {
                $this->warn("🔍 DRY RUN: Found {$orphanedCount} orphaned records");
                $this->warn("Total size: " . number_format($totalSize) . " bytes");
                $this->info("Run without --dry-run to actually delete them");
            } else {
                $this->info("✅ Cleaned up {$orphanedCount} orphaned media records");
                $this->info("Total size freed: " . number_format($totalSize) . " bytes");
            }
        }

        return Command::SUCCESS;
    }
}