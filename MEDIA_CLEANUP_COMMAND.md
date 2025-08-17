# Media Cleanup Command - Implementation Summary

## 🎯 Command Purpose
Delete media files older than specified hours from both **database** and **S3 storage**, ensuring complete cleanup.

## 🔧 Implementation Details

### **Command Signature:**
```bash
php artisan media:clear-old [--hours=24] [--dry-run]
```

### **Options:**
- `--hours=N` - Hours after which to consider media old (default: 24)
- `--dry-run` - Show what would be deleted without actually deleting

### **Storage Integration:**
- **Uses `MediaService::delete()`** which handles:
  - ✅ S3 file deletion via `Storage::disk($media->disk)->delete($media->path)`
  - ✅ Database record deletion
  - ✅ Tag relationship cleanup (`$media->tags()->detach()`)

## 🗂️ Files Modified

### **1. Main Command File:**
`app/Console/Commands/ClearMediaOlderThan24Hours.php`
- **Renamed signature** from `app:clear-media-older-than24-hours` to `media:clear-old`
- **Added comprehensive functionality** with proper error handling
- **Integrated MediaService** for consistent deletion logic

### **2. MediaController Fix:**
`app/Http/Controllers/Api/MediaController.php`
- **Updated `destroy()` method** to use `MediaService::delete()` instead of manual deletion
- **Ensures consistency** between API deletion and command cleanup

## 🚀 Features Implemented

### **Safety Features:**
1. **Dry-run mode** - Preview what will be deleted
2. **Interactive confirmation** - Requires user confirmation before deletion
3. **Transaction safety** - Each deletion wrapped in DB transaction
4. **Error handling** - Continues processing if individual files fail

### **Informative Output:**
1. **Detailed table** showing files to be deleted
2. **Progress indicators** with ✓/✗ symbols
3. **Summary statistics** including storage freed
4. **Human-readable file sizes** (B, KB, MB, GB, TB)

### **Logging:**
- **Error logging** for failed deletions with full context
- **Laravel Log integration** for debugging

## 🎯 Usage Examples

### **Preview what would be deleted (24 hours):**
```bash
php artisan media:clear-old --dry-run
```

### **Delete files older than 48 hours:**
```bash
php artisan media:clear-old --hours=48
```

### **Delete files older than 1 hour (testing):**
```bash
php artisan media:clear-old --hours=1
```

## 📊 Expected Output

### **Dry Run Example:**
```
Looking for media files older than 24 hours (before 2025-08-08 10:30:00)...
Found 3 media file(s) to delete:

+--------------------------------------+------------------+--------------+--------------+--------------------------------+---------------------+
| ID                                   | Name             | Size (bytes) | Storage Disk | Path                           | Created At          |
+--------------------------------------+------------------+--------------+--------------+--------------------------------+---------------------+
| 01234567-89ab-cdef-0123-456789abcdef | old_video.mp4    | 15,728,640   | s3           | zivo_media/abc123def456.mp4    | 2025-08-07 09:15:00 |
| 01234567-89ab-cdef-0123-456789abcdef | old_image.jpg    | 2,048,576    | s3           | zivo_media/def456ghi789.jpg    | 2025-08-07 08:30:00 |
+--------------------------------------+------------------+--------------+--------------+--------------------------------+---------------------+

DRY RUN: No files were actually deleted.
Run without --dry-run to perform the actual deletion.
```

### **Actual Deletion Example:**
```
Looking for media files older than 24 hours (before 2025-08-08 10:30:00)...
Found 2 media file(s) to delete:
[Table showing files]

Are you sure you want to delete these media files? This action cannot be undone. (yes/no) [no]:
> yes

✓ Deleted: old_video.mp4 (01234567-89ab-cdef-0123-456789abcdef)
✓ Deleted: old_image.jpg (01234567-89ab-cdef-0123-456789abcdef)

=== Cleanup Summary ===
Successfully deleted: 2 file(s)
Errors encountered: 0
Total storage freed: 17.78 MB

Media cleanup completed successfully!
```

## 🔄 Scheduling

### **Add to Laravel Scheduler (`app/Console/Kernel.php`):**
```php
protected function schedule(Schedule $schedule)
{
    // Run daily at 2 AM
    $schedule->command('media:clear-old --hours=24')
             ->dailyAt('02:00')
             ->withoutOverlapping();
    
    // Run weekly for older files (7 days)
    $schedule->command('media:clear-old --hours=168')
             ->weekly()
             ->sundays()
             ->at('03:00');
}
```

## 🛡️ Safety Considerations

### **Database Relationships:**
- **Tags are properly detached** before media deletion
- **Questions are cascade deleted** via foreign key constraints
- **User relationships maintained** (media deleted, user remains)

### **Storage Safety:**
- **Only deletes from specified disk** (s3, public, etc.)
- **Handles missing files gracefully** (won't crash if S3 file missing)
- **Environment aware** (testing uses 'public' disk, production uses 's3')

### **Error Recovery:**
- **Individual file failures don't stop batch processing**
- **Detailed error logging** for troubleshooting
- **Transaction rollback** for database consistency

## 🧪 Testing

### **Test Command Registration:**
```bash
php artisan media:clear-old --help
```

### **Test Dry Run:**
```bash
php artisan media:clear-old --hours=1 --dry-run
```

### **Test Actual Deletion (careful!):**
```bash
php artisan media:clear-old --hours=1
```

---
**✅ Implementation Complete!** The command safely deletes media from both database and S3 storage with comprehensive safety features and informative output. 