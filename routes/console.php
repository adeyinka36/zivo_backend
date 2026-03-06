<?php

use App\Jobs\AssignCurrentMediaJob;
use App\Jobs\AssignUnassignedMediaJob;
use App\Jobs\CheckRoundCompletionJob;
use App\Jobs\CleanupAndReassignMediaJob;
use App\Jobs\CleanupTempUploadsJob;
use App\Jobs\DeleteCompletedCampaignsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('media:track-failed-uploads')->weekly();

Schedule::job(new CleanupAndReassignMediaJob)->cron('0 */6 * * *');

Schedule::job(new DeleteCompletedCampaignsJob)->everyTwelveHours();

Schedule::job(new AssignCurrentMediaJob)->cron('0 */2 * * *');

Schedule::job(new CheckRoundCompletionJob)->cron('0 */3 * * *');

Schedule::job(new AssignUnassignedMediaJob)->everyThirtyMinutes();

Schedule::job(new CleanupTempUploadsJob)->hourly();
