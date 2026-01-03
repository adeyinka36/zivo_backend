<?php

namespace App\Jobs;

use App\Jobs\SendCurrentMediaNotificationJob;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to assign current media to users who have assignments but no current assignment.
 * 
 * Runs every 6 hours to:
 * 1. Find users who have assignments but no current assignment
 * 2. Randomly select one assignment per user and set it as current
 * 3. Send notifications to users with push_notifications enabled
 */
class AssignCurrentMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $updatedRecords = DB::select(<<<SQL
                WITH eligible_users AS (
                    SELECT DISTINCT ua.user_id
                    FROM user_assignments ua
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM user_assignments cur
                        WHERE cur.user_id = ua.user_id
                          AND cur.current = TRUE
                    )
                ),
                picks AS (
                    SELECT DISTINCT ON (ua.user_id) ua.id, ua.user_id, ua.media_id
                    FROM user_assignments ua
                    JOIN eligible_users eu ON eu.user_id = ua.user_id
                    WHERE ua.current = FALSE
                    ORDER BY ua.user_id, random()
                )
                UPDATE user_assignments ua
                SET current = TRUE
                FROM picks
                WHERE ua.id = picks.id
                RETURNING picks.user_id, picks.media_id
            SQL);

            $updatedCount = count($updatedRecords);

            $notificationsDispatched = 0;

            if ($updatedCount > 0) {
                $userIds = array_unique(array_column($updatedRecords, 'user_id'));
                $usersWithNotifications = User::whereIn('id', $userIds)
                    ->where('push_notifications', true)
                    ->pluck('id')
                    ->toArray();

                foreach ($updatedRecords as $record) {
                    if (in_array($record->user_id, $usersWithNotifications)) {
                        SendCurrentMediaNotificationJob::dispatch(
                            $record->user_id,
                            $record->media_id,
                            'assigned'
                        );
                        $notificationsDispatched++;
                    }
                }
            }

            Log::info('Assign current media job completed', [
                'rows_updated' => $updatedCount,
                'notifications_dispatched' => $notificationsDispatched,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $sqlState = $e->getSqlState() ?? '';
            if ($sqlState === '23505' || str_contains($e->getMessage(), 'unique constraint')) {
                Log::info('Assign current media job: unique constraint violation (likely concurrent execution)', [
                    'message' => $e->getMessage(),
                ]);
            } else {
                throw $e;
            }
        }
    }
}
