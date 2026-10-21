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
flock -n /home/osamwgct/backend.bookboll.com/storage/queue-worker.lock /usr/local/bin/php /home/osamwgct/backend.bookboll.com/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --sleep=1 >> /dev/null 2>&1
```

**`flock` is not optional.** A worker started every minute that takes longer than a
minute to drain leaves the previous one running, and each live worker holds a MySQL
connection. Shared hosting allows this account 30 connections in total, shared with
every web request, so a handful of stacked workers is enough to take the site down
with `max_user_connections`. `flock -n` makes the minute's cron exit immediately if a
worker is already running, and `--max-time=55` guarantees one never outlives its slot.

**⚠️ Important:** If your Laravel project is inside a subdirectory (e.g., `backend.osamasafdar.com/bookboll-BACKEND`), add that subdirectory to the path above.

## The 30-connection budget

Namecheap shared hosting caps this account at `max_user_connections: 30`. That is not
30 per worker or per site — it is 30 open MySQL connections for everything the account
does at once: every in-flight PHP request, every cron, every queue worker. Exceed it
and MySQL refuses the next connection outright:

```
SQLSTATE[HY000] [1226] User '..._production_user' has exceeded the
'max_user_connections' resource (current value: 30)
```

A connection is held for the **whole** request, from the first query to the response,
so the number that matters is concurrent requests that touch the database — not
queries per second. Keeping inside the budget means two things:

**1. Do not make every request a database request.** `CACHE_STORE` and `SESSION_DRIVER`
must be `file`, never `database`. With the database drivers, the cache read that
resolves CORS origins, the API rate limiter, and the session all open a connection on
requests that would otherwise need none — preflights, webhooks, health checks, 404s.
Thirty concurrent visitors is then the ceiling for the entire site.

**2. Do not let background work stack up.** Both crons run every minute; if a run
outlasts its minute, the next one starts alongside it and each holds its own
connection. The queue worker is wrapped in `flock -n` and `--max-time=55` above for
exactly this reason. Never use `queue:work --daemon` here.

After changing `.env`, clear the compiled config or the old driver stays live:

```bash
php artisan config:clear && php artisan cache:clear && php artisan config:cache && php artisan route:cache
```

Switching `SESSION_DRIVER` to `file` logs everyone out once — existing rows in the
`sessions` table are no longer read. That is the only visible effect.

To confirm what is actually holding connections, from cPanel Terminal:

```bash
mysqladmin -u USER -p processlist | wc -l
```

If that sits near 30 while traffic is light, something is leaking — look for stacked
`queue:work` processes first (`ps aux | grep artisan`).

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

**Command:**
```bash
flock -n /home/osamwgct/backend.bookboll.com/storage/queue-worker.lock /usr/local/bin/php /home/osamwgct/backend.bookboll.com/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --sleep=1 >> /dev/null 2>&1
```

**Note:** this drains the pending jobs and stops, and `flock -n` means a minute whose
predecessor is still working is skipped rather than doubled up. Do **not** use
`queue:work --daemon` on shared hosting: a long-lived worker holds a MySQL connection
for as long as it runs, and cPanel killing it mid-job leaves the job reserved.

**Alternative command format:**
```bash
cd /home/osamwgct/backend.bookboll.com && flock -n storage/queue-worker.lock /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=3 --sleep=1 >> /dev/null 2>&1
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
flock -n PROJECT_PATH/storage/queue-worker.lock PHP_PATH PROJECT_PATH/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --sleep=1 >> /dev/null 2>&1
```

## Example with Real Values

Based on your setup (username: `osamwgct`, project in `backend.osamasafdar.com`):

**Scheduler:**
```bash
/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/artisan schedule:run >> /dev/null 2>&1
```

**Queue Worker:**
```bash
flock -n /home/osamwgct/backend.bookboll.com/storage/queue-worker.lock /usr/local/bin/php /home/osamwgct/backend.bookboll.com/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --sleep=1 >> /dev/null 2>&1
```

**Note:** If your Laravel project is inside a subdirectory within `backend.osamasafdar.com`, add that subdirectory to the path. For example:
- If project is at: `/home/osamwgct/backend.osamasafdar.com/bookboll-BACKEND/`
- Then use: `/usr/local/bin/php /home/osamwgct/backend.osamasafdar.com/bookboll-BACKEND/artisan schedule:run >> /dev/null 2>&1`

---

**Note:** Always test your cron jobs manually first before setting them to run automatically. Remove `>> /dev/null 2>&1` initially to see any error messages.
