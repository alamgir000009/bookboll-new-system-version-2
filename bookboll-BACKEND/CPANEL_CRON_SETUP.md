# cPanel Cron Jobs Setup Guide

This guide will help you set up cron jobs in cPanel to run Laravel's scheduled tasks and queue workers.

## Quick Start - Copy & Paste Commands

**For your setup (project in `backend.osamasafdar.com`):**

### Cron Job 1: Laravel Scheduler
**Frequency:** Every Minute (`* * * * *`)
**Command:**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan schedule:run >> /dev/null 2>&1
```

### Cron Job 2: Queue Worker
**Frequency:** Every Minute (`* * * * *`)
**Command:**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

**⚠️ Important:** If your Laravel project is inside a subdirectory (e.g., `backend.osamasafdar.com/bookboll-BACKEND`), add that subdirectory to the path above.

## Prerequisites

Before setting up cron jobs, you need to know:
1. **Your cPanel username** (e.g., `osamwgct`)
2. **Your project path** (e.g., `/home/osamwgct/backend.osamasafdar.com`)
3. **PHP version** (check in cPanel → Select PHP Version)

## Step 1: Find Your PHP Path

1. Log in to cPanel
2. Go to **Select PHP Version** or **MultiPHP Manager**
3. Note your PHP version (e.g., PHP 8.1, PHP 8.2)
4. Common PHP paths in cPanel:
   - PHP 8.1: `/opt/cpanel/ea-php81/root/usr/bin/php`
   - PHP 8.2: `/opt/cpanel/ea-php82/root/usr/bin/php`
   - PHP 8.3: `/opt/cpanel/ea-php83/root/usr/bin/php`
   - Alternative: `/usr/local/bin/php` (may use default PHP version)

**To find your exact PHP path:**
- Create a test file `phpinfo.php` in your public_html with `<?php phpinfo(); ?>`
- Access it via browser and check the "Server API" and "Configuration File (php.ini) Path"
- Or run this command in cPanel Terminal: `which php`

## Step 2: Find Your Project Path

Your project path should be:
```
/home/YOUR_USERNAME/backend.osamasafdar.com
```

Replace `YOUR_USERNAME` with your actual cPanel username.

**Note:** If your project is in a subdirectory within `backend.osamasafdar.com`, add that path. For example:
- `/home/YOUR_USERNAME/backend.osamasafdar.com` (if project is at root)
- `/home/YOUR_USERNAME/backend.osamasafdar.com/bookboll-BACKEND` (if project is in a subfolder)

## Step 3: Set Up Cron Jobs in cPanel

1. Log in to cPanel
2. Navigate to **Cron Jobs** (under **Advanced** section)
3. You'll need to add **TWO** cron jobs:

### Cron Job 1: Laravel Scheduler (Required)

**Purpose:** Runs Laravel's scheduled tasks (slot-locks:clear every 5 minutes, send-booking-reminders daily)

**Settings:**
- **Minute:** `*`
- **Hour:** `*`
- **Day:** `*`
- **Month:** `*`
- **Weekday:** `*`

**Command:**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan schedule:run >> /dev/null 2>&1
```

**Important:** 
- Replace `/usr/local/bin/php` with your actual PHP path (or use `/opt/cpanel/ea-php81/root/usr/bin/php` if you have a specific PHP version)
- Replace `osamwgct` with your cPanel username if different
- If your project is in a subdirectory, add it to the path (e.g., `/home/osamwgct/backend.osamasafdar.com/bookboll-BACKEND`)

**Alternative command format (if above doesn't work):**
```bash
cd /home/osamwgct/backend.osamasafdar.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

### Cron Job 2: Queue Worker (Required)

**Purpose:** Processes queued jobs from the database

**Settings:**
- **Minute:** `*` (runs every minute)
- **Hour:** `*`
- **Day:** `*`
- **Month:** `*`
- **Weekday:** `*`

**Command (Option A - Recommended for low traffic):**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

**Command (Option B - For continuous processing):**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan queue:work --daemon --tries=3 --timeout=90 >> /dev/null 2>&1
```

**Note:** Option A processes all pending jobs and stops. Option B runs continuously (but may be killed by cPanel after a timeout).

**Alternative command format:**
```bash
cd /home/osamwgct/backend.osamasafdar.com && /usr/local/bin/php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

## Step 4: Testing Your Cron Jobs

### Test the Scheduler:
1. Go to cPanel → **Cron Jobs**
2. Click **Add New Cron Job**
3. Set frequency to **Once Per Minute**
4. Use this test command:
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan schedule:run
```
5. Remove `>> /dev/null 2>&1` to see output
6. Check the cron job email (if enabled) for any errors

### Test the Queue Worker:
1. Create a test command:
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan queue:work --once
```
2. Check for errors in the email output

## Step 5: Enable Email Notifications (Optional)

In cPanel Cron Jobs settings:
- **Email:** Enter your email address to receive cron job output
- This helps you monitor if cron jobs are running successfully

## Step 6: Verify Scheduled Tasks

Your Laravel application has these scheduled tasks (from `routes/console.php`):
- `slot-locks:clear` - Runs every 5 minutes
- `send-booking-reminders` - Runs daily

The scheduler cron job will automatically run these at their scheduled times.

## Troubleshooting

### Issue: Cron job not running
**Solution:**
1. Check the PHP path is correct
2. Check the project path is correct
3. Verify file permissions (artisan should be executable)
4. Check cron job email for error messages

### Issue: Permission denied
**Solution:**
1. Set correct permissions: `chmod +x artisan`
2. Ensure project files are owned by your cPanel user

### Issue: Queue jobs not processing
**Solution:**
1. Verify database connection in `.env`
2. Check that `QUEUE_CONNECTION=database` in `.env`
3. Ensure jobs table exists: `php artisan migrate`
4. Check queue:work command runs manually first

### Issue: Scheduler not running scheduled tasks
**Solution:**
1. Verify scheduler cron runs every minute
2. Check Laravel logs: `storage/logs/laravel.log`
3. Test manually: `php artisan schedule:run`

## Quick Reference Commands

Replace these placeholders:
- `YOUR_USERNAME` - Your cPanel username
- `PHP_PATH` - Your PHP executable path
- `PROJECT_PATH` - Full path to your Laravel project

**Scheduler (every minute):**
```bash
PHP_PATH PROJECT_PATH/artisan schedule:run >> /dev/null 2>&1
```

**Queue Worker (every minute):**
```bash
PHP_PATH PROJECT_PATH/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

## Example with Real Values

Based on your setup (username: `osamwgct`, project in `backend.osamasafdar.com`):

**Scheduler:**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan schedule:run >> /dev/null 2>&1
```

**Queue Worker:**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

**Note:** If your Laravel project is inside a subdirectory within `backend.osamasafdar.com`, add that subdirectory to the path. For example:
- If project is at: `/home/osamwgct/backend.osamasafdar.com/bookboll-BACKEND/`
- Then use: `/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/bookboll-BACKEND/artisan schedule:run >> /dev/null 2>&1`

---

**Note:** Always test your cron jobs manually first before setting them to run automatically. Remove `>> /dev/null 2>&1` initially to see any error messages.
