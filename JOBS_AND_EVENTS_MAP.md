# Jobs and Events Map

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

### 1. CheckRoundCompletionJob
- **Schedule**: Every 6 hours (`0 */6 * * *`)
- **Purpose**: Finds media with completed rounds and processes them
- **Actions**:
  - Queries media where watch count equals `target_views`
  - Excludes media where campaign is already complete
  - Dispatches `ProcessRoundCompletionJob` for each eligible media

### 2. AssignCurrentMediaJob
- **Schedule**: Every 6 hours (`0 */6 * * *`)
- **Purpose**: Ensures each user has exactly one "current" media assignment
- **Actions**:
  - Finds users who have assignments but no current assignment
  - Randomly selects one assignment per user and sets it as current
  - Dispatches `SendCurrentMediaNotificationJob` for users with push notifications enabled

### 3. CleanupAndReassignMediaJob
- **Schedule**: Daily
- **Purpose**: Removes stale assignments and reassigns media
- **Actions**:
  - Finds assignments that are:
    - `current = true`
    - Created more than 24 hours ago
    - Not watched (no matching record in `media_users_watched`)
  - Deletes stale assignments
  - Calls `MediaAssignmentService::assignMediaToUsers()` to reassign

### 4. DeleteCompletedCampaignsJob
- **Schedule**: Daily
- **Purpose**: Deletes completed campaigns from database and storage
- **Actions**:
  - Finds all media where `campaign_completed = true`
  - Deletes media files from S3/local storage via `MediaService::delete()`
  - Deletes media records from database
  - Continues processing even if individual deletions fail
  - Logs deletion statistics

### 5. AssignUnassignedMediaJob
- **Schedule**: Every 6 hours (`0 */6 * * *`)
- **Purpose**: Assigns users to media that have no assignments
- **Actions**:
  - Finds media where:
    - No UserAssignment records exist
    - Campaign is not complete
    - Payment status is 'paid'
    - Active is true
    - Media is uploaded (not pending)
  - Assigns users via `MediaAssignmentService::assignMediaToUsers()`
  - Logs processing results and handles errors gracefully

### 6. media:track-failed-uploads
- **Schedule**: Weekly
- **Purpose**: Tracks failed media uploads
- **Type**: Artisan command

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
  - Associates locations with media via `media->locations()->sync()`
- **Performance**: Uses batch database operations (processes 500 locations per chunk) instead of individual queries
- **Note**: Runs asynchronously and doesn't block media upload or assignment. Media can be assigned to users even if location expansion is still running.

#### 3. ProcessMediaUploadJob
- **Triggered**: Dispatched by `UploadFileToS3Job` after file upload completes
- **Dispatch**: Dispatched from `UploadFileToS3Job` with `afterCommit()`
- **Purpose**: Processes the uploaded media file
- **Actions**:
  - Stores media metadata
  - Processes questions if provided
  - Updates media with path, disk, mime_type, and marks as active and payment_status as 'paid'
  - **Note**: Dependent jobs are dispatched automatically via Media model's `updated` event after the database transaction commits

#### 4. GenerateThumbnailJob
- **Triggered**: Dispatched by Media model's `updated` event (if media is a video)
- **Dispatch**: Dispatched from Media model event listener
- **Purpose**: Generates thumbnail for video media
- **Condition**: Only dispatched if `mime_type` starts with `video/`
- **Prerequisites**: Media is already processed (path, disk, mime_type are set and saved to database)
- **Note**: Runs independently - if it fails, other jobs continue

#### 5. AssignMediaToUsers
- **Triggered**: Dispatched by Media model's `updated` event
- **Dispatch**: Dispatched from Media model event listener
- **Purpose**: Assigns media to users to reach target count
- **Prerequisites**: Media is already active and payment_status is 'paid' (guaranteed by ProcessMediaUploadJob)
- **Actions**:
  - Uses `MediaAssignmentService::assignMediaToUsers()`
  - Selects users based on location priority (coordinate-based radius search)
  - Creates `UserAssignment` records
  - Marks assignments as `current = true` if user has no current assignment
  - Dispatches `SendCurrentMediaNotificationJob` for users who received current assignments
- **Note**: Runs independently - if it fails, other jobs continue

#### 6. SendPushNotificationJob
- **Triggered**: Dispatched by Media model's `updated` event
- **Dispatch**: Dispatched from Media model event listener
- **Purpose**: Sends push notifications about new media upload
- **Prerequisites**: User and media exist (guaranteed by ProcessMediaUploadJob)
- **Actions**:
  - Sends upload success notification to creator
  - Sends notification to all subscribed users about new media
- **Note**: Runs independently - if it fails, other jobs continue

### Round Completion Flow

#### 7. ProcessRoundCompletionJob
- **Triggered**: By `CheckRoundCompletionJob` (scheduled every 6 hours)
- **Purpose**: Processes a completed round for a media item
- **Actions**:
  - Verifies round is complete
  - If campaign not complete:
    - Increments `rounds_completed`
    - Resets watches (deletes `MediaUserWatched` records)
    - Resets assignments (deletes `UserAssignment` records)
    - Reassigns media via `MediaAssignmentService::assignMediaToUsers()`
  - If campaign complete:
    - Marks campaign as complete
    - Sends push notification to uploader via `NotificationService::sendCampaignCompletionNotification()`

### Quiz Flow

#### 8. AllocateReward
- **Triggered**: When user answers quiz correctly (`MediaController::submitQuizResult`)
- **Purpose**: Allocates reward to user who won the quiz
- **Condition**: Only dispatched if answer is correct

#### 9. AllocateRewardWithAmount
- **Triggered**: When round completes without a winner (`MediaWatchService::allocateRewardsForUnwonRound`)
- **Purpose**: Allocates reward to randomly selected users
- **Actions**:
  - Distributes reward among active verified users
  - Dispatched for each winner

#### 10. QuizInvitation
- **Triggered**: By `QuizManager` command (scheduled separately)
- **Purpose**: Sends quiz invitations for eligible media
- **Condition**: Media must have been created more than 6 hours ago and have been watched

### Notification Jobs

#### 11. SendCurrentMediaNotificationJob
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
  - `GenerateThumbnailJob` (if `mime_type` starts with `video/`)
  - `AssignMediaToUsers`
  - `SendPushNotificationJob` (if `user_id` exists)
- **Note**: This ensures dependent jobs only run after the database transaction commits and the media is fully processed

---

## Job Flow Diagrams

### Media Upload Flow

```
User uploads media after payment
    ↓
MediaController::uploadAfterPayment()
    ↓
UploadFileToS3Job::dispatch() (afterCommit)
    ↓
UploadFileToS3Job uploads file to S3 and dispatches:
    - ProcessMediaUploadJob (afterCommit)
    - ExpandLocationSelectionsJob (afterCommit, if locations exist)
    ↓
ProcessMediaUploadJob updates Media record:
    - path, disk, mime_type, etc.
    - payment_status = 'paid'
    - active = true
    ↓
Media model's updated event fires (after DB commit)
    ↓
Event listener detects transition from pending → processed
    ↓
Dispatches dependent jobs independently:
    GenerateThumbnailJob           → Generate thumbnail (if video)
    AssignMediaToUsers             → Assign to users
    SendPushNotificationJob        → Notify creator
    SendEmailNotificationJob       → Email creator
    
ExpandLocationSelectionsJob (runs in parallel):
    - Expands location selections using batch operations
    - Creates/finds locations in database (500 per chunk)
    - Associates locations with media
    
Note: 
- File upload and location expansion happen asynchronously
- Dependent jobs are dispatched via Media model event after database commit
- Location expansion doesn't block media assignment (MediaAssignmentService handles empty locations gracefully)
- If one job fails, others continue processing
```

### Round Completion Flow

```
CheckRoundCompletionJob (every 6 hours)
    ↓
Finds media with watch_count = target_views
    ↓
For each media:
    ProcessRoundCompletionJob
        ↓
    If round complete:
        - Increment rounds_completed
        - Delete all MediaUserWatched records
        - Delete all UserAssignment records
        - MediaAssignmentService::assignMediaToUsers()
            ↓
        Creates new assignments
            ↓
        For users without current assignment:
            - Mark assignment as current = true
            - SendCurrentMediaNotificationJob (via MediaAssignmentService)
    If campaign complete:
        - Mark campaign_completed = true
        - NotificationService::sendCampaignCompletionNotification()
            ↓
        Uploader receives push notification
```

### Campaign Deletion Flow

```
DeleteCompletedCampaignsJob (daily)
    ↓
Finds all media where campaign_completed = true
    ↓
For each media:
    MediaService::delete()
        ↓
    - Delete file from S3/local storage
    - Delete media record from database
    - Log deletion result
```

### Unassigned Media Flow

```
AssignUnassignedMediaJob (every 6 hours)
    ↓
Finds media with no assignments where:
    - campaign_completed = false
    - payment_status = 'paid'
    - active = true
    - mime_type != 'pending'
    - No UserAssignment records exist
    ↓
For each media:
    MediaAssignmentService::assignMediaToUsers()
        ↓
    Creates assignments
        ↓
    For users without current assignment:
        - Mark assignment as current = true
        - SendCurrentMediaNotificationJob (via MediaAssignmentService)
```

### Current Assignment Flow

```
AssignCurrentMediaJob (every 6 hours)
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

### Quiz Flow

```
User watches media
    ↓
MediaWatchService::markAsWatched()
    ↓
If watch_count == quiz_number:
    - Returns quiz data to frontend
    - Frontend shows quiz
        ↓
User submits answer
    ↓
MediaController::submitQuizResult()
    ↓
If answer correct:
    AllocateReward::dispatch()
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

### AllocateReward Triggers

1. **Quiz Answer** (via `MediaController::submitQuizResult()`)
   - When: User answers quiz correctly
   - Condition: Answer matches question answer exactly

### AllocateRewardWithAmount Triggers

1. **Round Completion Without Winner** (via `MediaWatchService::allocateRewardsForUnwonRound()`)
   - When: Round completes but no one answered quiz correctly
   - Condition: Media has reward to distribute
   - Action: Distributes reward to random active verified users

---

## Job Dependencies

### Independent Jobs (Run in parallel, handle own prerequisites)
- `UploadFileToS3Job` → `ProcessMediaUploadJob` → `GenerateThumbnailJob` → `AssignMediaToUsers` → `SendPushNotificationJob` → `SendEmailNotificationJob`
- `UploadFileToS3Job` → `ExpandLocationSelectionsJob` (runs in parallel with ProcessMediaUploadJob)

### Independent Jobs (Can run in parallel)
- `CheckRoundCompletionJob`, `AssignCurrentMediaJob`, and `AssignUnassignedMediaJob` (all run every 6 hours, independent)
- `CleanupAndReassignMediaJob` and `DeleteCompletedCampaignsJob` (both run daily, independent)

### Job Dependencies
- `ProcessMediaUploadJob` depends on `UploadFileToS3Job` (dispatched by it)
- `ExpandLocationSelectionsJob` depends on `UploadFileToS3Job` (dispatched by it, if locations exist)
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
- **Location Processing**: Location expansion uses batch database operations for performance. Large selections (e.g., entire continents) are processed efficiently in chunks of 500 locations, reducing database queries from hundreds to just a few bulk operations.
- **Location Expansion**: Runs asynchronously and doesn't block media upload or user assignment. If locations aren't expanded yet when `AssignMediaToUsers` runs, it gracefully falls back to general user assignment without location filtering.