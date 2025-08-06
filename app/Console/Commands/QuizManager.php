<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;

class QuizManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:quiz-manager';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manages scheduling quiz invitations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $allMediaThatHaveQuizzesAndHaveBeenWatched = Media::where('reward_won', true)
            ->whereHas('watchedByUsers')
            ->whereBetween('created_at', [now()->subDays(7), now()->subDays(3)]) // Between 7 and 3 days ago
            ->where('has_reward', true)
            ->get();

        if ($allMediaThatHaveQuizzesAndHaveBeenWatched->isEmpty()) {
            $this->info('No media qualifies for quizzes.');
            return;
        }

        foreach ($allMediaThatHaveQuizzesAndHaveBeenWatched as $media) {
            if ($media->questions()->count() > 0) {
                $this->info("Scheduling quiz invitation for media ID: {$media->id}");
                \App\Jobs\QuizInvitation::dispatch($media);
            } else {
                $this->warn("No quizzes found for media ID: {$media->id}");
            }
        }
    }

}
