<?php

use App\Jobs\AssignCurrentMediaJob;
use App\Jobs\CheckRoundCompletionJob;
use App\Jobs\CleanupAndReassignMediaJob;
use App\Jobs\DeleteCompletedCampaignsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('media:track-failed-uploads')->weekly();

Schedule::job(new CleanupAndReassignMediaJob)->daily();

Schedule::job(new DeleteCompletedCampaignsJob)->daily();

Schedule::job(new AssignCurrentMediaJob)->cron('0 */6 * * *');

Schedule::job(new CheckRoundCompletionJob)->cron('0 */6 * * *');
