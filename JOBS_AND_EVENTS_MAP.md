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

### 5. media:track-failed-uploads
- **Schedule**: Weekly
- **Purpose**: Tracks failed media uploads
- **Type**: Artisan command

---

## Dispatched Jobs

These jobs are dispatched programmatically in response to user actions or other events.

### Media Upload Flow

#### 1. ProcessMediaUploadJob
- **Triggered**: When media is uploaded after payment (`MediaController::uploadAfterPayment`)
- **Chain Position**: First in chain
- **Purpose**: Processes the uploaded media file
- **Actions**:
  - Stores media metadata
  - Processes questions if provided

#### 2. GenerateThumbnailJob
- **Triggered**: When media is uploaded after payment (if media is a video)
- **Chain Position**: Second in chain (conditional)
- **Purpose**: Generates thumbnail for video media
- **Condition**: Only dispatched if `mime_type` starts with `video/`

#### 3. AssignMediaToUsers
- **Triggered**: When media is uploaded after payment
- **Chain Position**: Third in chain
- **Purpose**: Assigns media to users to reach target count
- **Actions**:
  - Uses `MediaAssignmentService::assignMediaToUsers()`
  - Selects users based on location priority (coordinate-based radius search)
  - Creates `UserAssignment` records
  - Marks assignments as `current = true` if user has no current assignment
  - Dispatches `SendCurrentMediaNotificationJob` for users who received current assignments

#### 4. SendPushNotificationJob
- **Triggered**: When media is uploaded after payment
- **Chain Position**: Last in chain
- **Purpose**: Sends push notifications about new media upload
- **Actions**:
  - Sends upload success notification to creator
  - Sends notification to all subscribed users about new media

### Round Completion Flow

#### 5. ProcessRoundCompletionJob
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

#### 6. AllocateReward
- **Triggered**: When user answers quiz correctly (`MediaController::submitQuizResult`)
- **Purpose**: Allocates reward to user who won the quiz
- **Condition**: Only dispatched if answer is correct

#### 7. AllocateRewardWithAmount
- **Triggered**: When round completes without a winner (`MediaWatchService::allocateRewardsForUnwonRound`)
- **Purpose**: Allocates reward to randomly selected users
- **Actions**:
  - Distributes reward among active verified users
  - Dispatched for each winner

#### 8. QuizInvitation
- **Triggered**: By `QuizManager` command (scheduled separately)
- **Purpose**: Sends quiz invitations for eligible media
- **Condition**: Media must have been created more than 6 hours ago and have been watched

### Notification Jobs

#### 9. SendCurrentMediaNotificationJob
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

---

## Job Flow Diagrams

### Media Upload Flow

```
User uploads media after payment
    ↓
MediaController::uploadAfterPayment()
    ↓
Bus::chain([
    ProcessMediaUploadJob          → Process file & metadata
    GenerateThumbnailJob           → Generate thumbnail (if video)
    AssignMediaToUsers             → Assign to users
    SendPushNotificationJob        → Notify creator & subscribers
])
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

### Chained Jobs (Must run in order)
- `ProcessMediaUploadJob` → `GenerateThumbnailJob` → `AssignMediaToUsers` → `SendPushNotificationJob`

### Independent Jobs (Can run in parallel)
- `CheckRoundCompletionJob` and `AssignCurrentMediaJob` (both run every 6 hours, independent)
- `CleanupAndReassignMediaJob` and `DeleteCompletedCampaignsJob` (both run daily, independent)

### Job Dependencies
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
- Job chains ensure sequential execution and stop on first failure
- Notifications are only sent to users with `push_notifications = true` and a valid `push_token`
