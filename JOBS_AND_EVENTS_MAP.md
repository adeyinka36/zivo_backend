# Jobs and Events Map

The current map of user flows, jobs, and events is in [`docs/flows`](../docs/flows/README.md). The schedule there follows `routes/console.php`. Some intervals in this file (for example cleanup every 6 hours) are older than that schedule.

This document maps out all jobs, events, and their triggers in the Zivo backend application.

## Table of Contents
1. [Scheduled Jobs](#scheduled-jobs)
2. [Dispatched Jobs](#dispatched-jobs)
3. [Model Events/Observers](#model-eventsobservers)
4. [Job Flow Diagrams](#job-flow-diagrams)
5. [Event Triggers](#event-triggers)

---

## Scheduled Jobs

These jobs run automatically on a schedule defined in `routes/console.php`.

| Job | Schedule |
|-----|----------|
| media:track-failed-uploads | Weekly |
| CleanupAndReassignMediaJob | Every 6 hours (`0 */6 * * *`) |
| DeleteCompletedCampaignsJob | Every 12 hours (`0 */12 * * *`) |
| AssignCurrentMediaJob | Every 2 hours (`0 */2 * * *`) |
| CheckRoundCompletionJob | Every 3 hours (`0 */3 * * *`) |
| AssignUnassignedMediaJob | Every 30 minutes |
| CleanupTempUploadsJob | Hourly |

### 1. CheckRoundCompletionJob
- **Schedule**: Every 3 hours (`0 */3 * * *`)
- **Purpose**: Finds media with completed rounds and processes them
- **Actions**:
  - Queries media where watch count equals `target_views` (from `config('services.media.target_views')`)
  - Excludes media where campaign is already complete
  - Dispatches `ProcessRoundCompletionJob` for each eligible media

### 2. AssignCurrentMediaJob
- **Schedule**: Every 2 hours (`0 */2 * * *`)
- **Purpose**: Ensures each user has exactly one "current" media assignment
- **Actions**:
  - Finds users who have assignments but no current assignment
  - Randomly selects one assignment per user and sets it as current
  - Dispatches `SendCurrentMediaNotificationJob` for users with push notifications enabled

### 3. CleanupAndReassignMediaJob
- **Schedule**: Every 6 hours (`0 */6 * * *`)
- **Purpose**: Removes stale assignments and reassigns media so users have 6 hours to watch assigned media
- **Actions**:
  - Finds assignments that are:
    - `current = true`
    - Created more than 6 hours ago
    - Not watched (no matching record in `media_users_watched`)
  - Deletes stale assignments
  - Calls `MediaAssignmentService::assignMediaToUsers()` which dispatches `AssignMediaToUsersJob` to reassign

### 4. DeleteCompletedCampaignsJob
- **Schedule**: Every 12 hours (`0 */12 * * *`)
- **Purpose**: Deletes completed or expired campaigns from database and storage after sending a completion email
- **Actions**:
  - Finds all media where any of:
    - `campaign_completed = true`, OR
    - `to_date` is set and more than 24 hours in the past (UTC), OR
    - `target_reached_at` is set and more than 24 hours in the past (UTC)
  - For each media: determines reason (`completed`, `expired`, or `target_reached`), marks `campaign_completed = true` if not already, dispatches `SendCampaignCompletionEmailJob` with that reason, then deletes media via `MediaService::delete()`
  - Continues processing even if individual deletions fail
  - Logs deletion statistics

### 5. AssignUnassignedMediaJob
- **Schedule**: Every 30 minutes
- **Purpose**: Activates media when their window starts, and assigns users to media that need more assignments
- **Actions**:
  - **Activation**: Finds media where `from_date <= now <= to_date`, `active = false`, payment paid and uploaded; sets `active = true` and updates related `Campaign` to `active = true`
  - **Assignment**: Finds media where campaign not complete, payment 'paid', active, uploaded, within date range, and current round needs more assignments (based on `target_views` and assignment count)
  - Dispatches `AssignMediaToUsersJob` for each eligible media
  - Logs processing results and handles errors gracefully

### 6. media:track-failed-uploads
- **Schedule**: Weekly
- **Purpose**: Tracks failed media uploads
- **Type**: Artisan command

### 7. CleanupTempUploadsJob
- **Schedule**: Hourly
- **Purpose**: Cleans up old temporary upload files
- **Actions**:
  - Scans `temp_uploads/` directory
  - Deletes files older than 24 hours
  - Logs cleanup statistics (deleted, kept, errors)
  - Prevents disk space issues while preserving files needed for recent payments
- **Note**: Files are kept for 24 hours to ensure they're available even if payment is delayed

---

## Dispatched Jobs

These jobs are dispatched programmatically in response to user actions or other events.

### Media Upload Flow

#### 1. UploadFileToS3Job
- **Triggered**: When media is uploaded after payment (`MediaController::uploadAfterPayment`)
- **Dispatch**: Dispatched from controller with `afterCommit()`
- **Purpose**: Uploads media file to S3 and dispatches processing jobs
- **Actions**:
  - Uploads file to S3 using streaming (for large files)
  - Verifies file upload succeeded
  - Dispatches `ProcessMediaUploadJob` with file metadata
  - Dispatches `ExpandLocationSelectionsJob` if location selections exist
  - Cleans up temporary file
- **Note**: File upload happens asynchronously to avoid blocking the request

#### 2. ExpandLocationSelectionsJob
- **Triggered**: Dispatched by `UploadFileToS3Job` if location selections exist
- **Dispatch**: Dispatched from `UploadFileToS3Job` with `afterCommit()`
- **Purpose**: Expands location selections to individual city locations
- **Actions**:
  - Processes location selections (city, country, continent) using batch operations
  - Creates or finds locations in database using efficient bulk queries
  - Associates locations with media (using `attach()` for new locations, `syncWithoutDetaching()` for existing to avoid duplicate key errors)
  - Dispatches `AssignMediaToUsersJob` after locations are synced (ensures locations are available for assignment)
  - If no location selections exist, dispatches `AssignMediaToUsersJob` directly
- **Performance**: Uses batch database operations (processes 500 locations per chunk) instead of individual queries
- **Note**: Dispatches `AssignMediaToUsersJob` after locations are synced to ensure location-based assignment works correctly

#### 3. ProcessMediaUploadJob
- **Triggered**: Dispatched by `UploadFileToS3Job` after file upload completes
- **Dispatch**: Dispatched from `UploadFileToS3Job` with `afterCommit()`
- **Purpose**: Processes the uploaded media file and creates campaign record
- **Actions**:
  - Stores media metadata (path, disk, mime_type, etc.)
  - Processes questions if provided
  - Updates media with path, disk, mime_type, and marks as active and payment_status as 'paid'
  - Creates or updates a `Campaign` record for the media (creator_id, media_id, active)
  - Attaches location_id to media when applicable (no radius-based logic)
  - **Note**: Dependent jobs are dispatched automatically via Media model's `updated` event after the database transaction commits

#### 4. AssignMediaToUsersJob
- **Triggered**: Dispatched by Media model's `updated` event (if no location IDs), by `ProcessMediaUploadJob` (if location IDs exist), by `ExpandLocationSelectionsJob` (after location expansion), by `ProcessRoundCompletionJob` (after resetting assignments for new round), by `AssignUnassignedMediaJob` (scheduled), or by `CleanupAndReassignMediaJob` (via `MediaAssignmentService::assignMediaToUsers()`)
- **Dispatch**: Dispatched from Media model event listener or directly from other jobs
- **Purpose**: Assigns media to users to reach target view count (from `config('services.media.target_views')`)
- **Prerequisites**: Media is already active and payment_status is 'paid'
- **Actions**:
  - Selects users (location-based when media has locations; otherwise general assignment)
  - Creates `UserAssignment` records
  - Marks assignments as `current = true` if user has no current assignment
- **Note**: Single job used for all assignment scenarios; runs independently

#### 5. SendEmailNotificationJob
- **Triggered**: Dispatched by Media model's `updated` event when creator_id exists
- **Purpose**: Sends email to creator when media upload has been processed successfully

### Round Completion Flow

#### 6. ProcessRoundCompletionJob
- **Triggered**: By `CheckRoundCompletionJob` (scheduled every 3 hours)
- **Purpose**: Processes a completed round for a media item and handles campaign completion
- **Actions**:
  - Verifies round is complete (watch count equals `target_views`)
  - If campaign not complete:
    - Increments `rounds_completed` only
    - Resets watches (deletes `MediaUserWatched` records)
    - Resets assignments (deletes `UserAssignment` records)
    - Dispatches `AssignMediaToUsersJob` directly
  - If campaign complete:
    - Sets `target_reached_at` if not already set; dispatches `SendCampaignCompletionEmailJob` with reason `target_reached`
    - Marks campaign as complete
    - Dispatches `SendCampaignCompletionEmailJob` with reason `completed` if not already notified

### Quiz and Offer Flow

- **Quiz**: Every first-time watch of media that has a question triggers a quiz for the user.
- **Correct answer**: No Tremendous or voucher reward. A correct answer unlocks the campaign's **offer**, delivered to the user by email. `AllocateReward` job exists but does not allocate monetary rewards.

#### 7. QuizInvitation
- **Triggered**: By `QuizManager` command (scheduled separately)
- **Purpose**: Sends quiz invitations for eligible media
- **Condition**: Media must have been created more than 6 hours ago and have been watched

### Notification Jobs

#### 8. SendCurrentMediaNotificationJob
- **Triggered**: Multiple sources (see [Event Triggers](#event-triggers))
- **Purpose**: Sends push notification when media becomes current or is removed
- **Types**:
  - `'assigned'`: Media assigned as current
  - `'removed'`: Current media removed

---

## Model Events/Observers

### UserAssignment Model Events

#### On Update
- **Event**: `updated`
- **Condition**: `current` field changed to `true` (from `false` or `null`)
- **Action**: Dispatches `SendCurrentMediaNotificationJob` with type `'assigned'`
- **Condition**: Only if user has `push_notifications = true`

#### On Delete
- **Event**: `deleted`
- **Condition**: Assignment had `current = true`
- **Action**: Dispatches `SendCurrentMediaNotificationJob` with type `'removed'`
- **Condition**: Only if user has `push_notifications = true`

### MediaUserWatched Model Events

#### On Create
- **Event**: `created`
- **Action**: Increments `view_count` on the related `Media` model

### Media Model Events

#### On Update
- **Event**: `updated`
- **Condition**: Media transitions from pending to processed state:
  - Original `path` was `'pending'` and original `disk` was `'pending'`
  - New `path` is not `'pending'` and new `disk` is not `'pending'`
  - `payment_status` is `'paid'`
  - `active` is `true`
- **Action**: Dispatches dependent jobs:
  - `AssignMediaToUsersJob` (only if no location IDs exist on the media)
  - `SendEmailNotificationJob` (if `creator_id` exists)
- **Note**: `GenerateThumbnailJob` exists but is not currently dispatched (commented out in Media model). Push notifications for creators are not sent from this event (web only). Dependent jobs run after the database transaction commits.

---

## Job Flow Diagrams

### Media Upload Flow

```
User uploads file and creates payment intent
    ↓
MediaController::createPaymentIntent() (with file)
    ↓
File uploaded to temp storage (temp_uploads/)
    ↓
File existence verified
    ↓
temp_file_path stored in Media record
    ↓
Payment intent created
    ↓
User completes payment
    ↓
MediaController::uploadAfterPayment() (without file)
    ↓
Checks for temp_file_path in Media record
    ↓
Uses pre-uploaded temp file (or falls back to uploaded file)
    ↓
UploadFileToS3Job::dispatch() (afterCommit)
    ↓
UploadFileToS3Job uploads file to S3 and dispatches:
    - ProcessMediaUploadJob (afterCommit)
    ↓
ProcessMediaUploadJob updates Media record and creates Campaign:
    - path, disk, mime_type, payment_status = 'paid', active = true
    - Campaign::firstOrCreate(media_id, creator_id, active)
    ↓
ProcessMediaUploadJob dispatches (if location_selections exist):
    - ExpandLocationSelectionsJob (afterCommit)
    ↓
Media model's updated event fires (after DB commit)
    ↓
Dispatches: AssignMediaToUsersJob (only if no location IDs), SendEmailNotificationJob (if creator_id)
    
ProcessMediaUploadJob attaches location_id when applicable; ExpandLocationSelectionsJob expands
    location_selections and dispatches AssignMediaToUsersJob after locations synced.
    
Note: File upload happens before payment. Temp files cleaned after 24h by CleanupTempUploadsJob.
```

### Round Completion Flow

```
CheckRoundCompletionJob (every 3 hours)
    ↓
Finds media with watch_count = target_views
    ↓
For each media:
    ProcessRoundCompletionJob
        ↓
    If round complete (campaign not complete):
        - Increment rounds_completed
        - Delete MediaUserWatched and UserAssignment records
        - AssignMediaToUsersJob::dispatch()
    If campaign complete:
        - Set target_reached_at; SendCampaignCompletionEmailJob (reason: target_reached)
        - Mark campaign_completed = true
        - SendCampaignCompletionEmailJob (reason: completed)
```

### Campaign Deletion Flow

```
DeleteCompletedCampaignsJob (every 12 hours)
    ↓
Finds media where: campaign_completed = true OR to_date > 24h ago OR target_reached_at > 24h ago
    ↓
For each media:
    Determine reason (completed | expired | target_reached)
    Mark campaign_completed = true if not already
    SendCampaignCompletionEmailJob::dispatch(creator_id, media_id, reason)
    MediaService::delete()
        ↓
    - Delete file from S3/local storage
    - Delete media record from database
    - Log deletion result
```

### Unassigned Media Flow

```
AssignUnassignedMediaJob (every 30 minutes)
    ↓
Activation: media where from_date <= now <= to_date, active = false
    → Set active = true, Campaign active = true
    ↓
Finds media where:
    - campaign_completed = false, payment_status = 'paid', active = true
    - mime_type != 'pending', within from_date/to_date
    - Current round needs more assignments (vs target_views)
    ↓
For each media:
    AssignMediaToUsersJob::dispatch()
        ↓
    Creates assignments; marks current = true where user has no current
```

### Current Assignment Flow

```
AssignCurrentMediaJob (every 2 hours)
    ↓
Finds users without current assignment
    ↓
For each user:
    - Randomly selects one non-current assignment
    - Sets current = true
    - UserAssignment::updated event fires
        ↓
    SendCurrentMediaNotificationJob (via model observer)
```

### Quiz and Offer Flow

```
User watches media (first time)
    ↓
MediaWatchService::markAsWatched()
    ↓
If media has question(s): returns quiz data to frontend; frontend shows quiz
    ↓
User submits answer
    ↓
MediaController::processQuizResult()
    ↓
If answer correct: user unlocks campaign offer (delivered by email); no monetary reward job
```

---

## Event Triggers

### SendCurrentMediaNotificationJob Triggers

1. **Media Upload** (via `MediaAssignmentService::createAssignments()`)
   - When: New assignment created with `current = true`
   - Condition: User has no existing current assignment
   - Type: `'assigned'`

2. **UserAssignment Model Observer** (on `updated` event)
   - When: Assignment's `current` field changes from `false` to `true`
   - Condition: User has `push_notifications = true`
   - Type: `'assigned'`

3. **UserAssignment Model Observer** (on `deleted` event)
   - When: Assignment with `current = true` is deleted
   - Condition: User has `push_notifications = true`
   - Type: `'removed'`

4. **AssignCurrentMediaJob** (scheduled)
   - When: Job sets an assignment as current
   - Condition: User has `push_notifications = true`
   - Type: `'assigned'`

5. **Round Completion** (via `ProcessRoundCompletionJob`)
   - When: After deleting all assignments and reassigning
   - Condition: User receives new assignment with `current = true`
   - Type: `'assigned'`

### Quiz Result (Offer Unlock)

1. **Quiz Answer** (via `MediaController::processQuizResult()`)
   - When: User answers quiz correctly
   - Action: User unlocks campaign offer (delivered by email); no monetary reward job

---

## Job Dependencies

### Independent Jobs (Run in parallel, handle own prerequisites)
- `UploadFileToS3Job` → `ProcessMediaUploadJob` → Media `updated` event → `AssignMediaToUsersJob` (if no location IDs), `SendEmailNotificationJob`
- `UploadFileToS3Job` → `ProcessMediaUploadJob` → `ExpandLocationSelectionsJob` → `AssignMediaToUsersJob` (runs after locations expanded)
### Independent Jobs (Can run in parallel)
- `CheckRoundCompletionJob` (every 3 hours), `AssignCurrentMediaJob` (every 2 hours), and `AssignUnassignedMediaJob` (every 30 minutes) run independently at their own intervals.
- `CleanupAndReassignMediaJob` (every 6 hours) and `DeleteCompletedCampaignsJob` (every 12 hours) run independently.
- `CleanupTempUploadsJob` (hourly) runs independently.

### Job Dependencies
- `ProcessMediaUploadJob` depends on `UploadFileToS3Job` (dispatched by it)
- `ExpandLocationSelectionsJob` depends on `ProcessMediaUploadJob` (dispatched by it after successful media processing, if locations exist)
- `ProcessRoundCompletionJob` depends on `CheckRoundCompletionJob` (dispatched by it)
- `SendCurrentMediaNotificationJob` depends on assignment creation/updates
- `AllocateReward` depends on quiz submission

---

## Queue Configuration

- **Default Connection**: Redis (configured in `.env`)
- **Queue Name**: `default`
- **Retry Policy**: Varies by job (see individual job `$tries` and `$backoff` properties)

---

## Notes

- All scheduled jobs require Laravel's scheduler to be running (`php artisan schedule:run`)
- Queue workers must be running to process dispatched jobs (`php artisan queue:work`)
- Model observers run synchronously within the same request/transaction
- Jobs are dispatched independently and handle their own prerequisites
- If one job fails, others continue processing independently
- Each job checks for required conditions before executing
- Notifications are only sent to users with `push_notifications = true` and a valid `push_token`
- **Location Processing**: Location expansion uses batch database operations for performance. Large selections (e.g., entire continents) are processed efficiently in chunks of 500 locations.
- **Media Assignment**: All assignments go through `AssignMediaToUsersJob`, dispatched by `AssignUnassignedMediaJob`, `ProcessRoundCompletionJob`, `CleanupAndReassignMediaJob` (via `MediaAssignmentService`), or the Media model `updated` event when there are no location IDs.
- **Campaigns**: Each media has an optional `Campaign` record (creator_id, media_id, active). Completion emails are sent with a reason: `completed`, `expired`, or `target_reached`.