<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

class FindAndDeleteMediaAfterTargetReached extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meda:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Deleting media after the quiz has been triggered database and storage (S3)";


    /**
     * Create a new command instance.
     */
    public function __construct(private readonly MediaService $mediaService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting media cleanup process...');

        $mediaToDelete = Media::where('quiz_played', true)
            ->get();

        if ($mediaToDelete->isEmpty()) {
            $this->info('No media found for deletion.');
            return CommandAlias::SUCCESS;
        }

        DB::beginTransaction();
        try {
            foreach ($mediaToDelete as $media) {
                // Delete from storage
                $this->mediaService->delete($media);

                // Delete from database
                $media->delete();
                $this->info("Deleted media: {$media->id}");
            }
            DB::commit();
            $this->info('Media cleanup completed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error during media cleanup: ' . $e->getMessage());
            $this->error('An error occurred during the cleanup process.');
            return CommandAlias::FAILURE;
        }

        return CommandAlias::SUCCESS;
    }
}
