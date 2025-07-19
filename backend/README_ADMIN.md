# Admin Backend Documentation

## Overview

This comprehensive admin system provides both command-line and API-based administration for your Telegram Scheduler application. It includes user management, system monitoring, analytics, and maintenance tools.

## Installation & Setup

### 1. Add Admin Configuration

Add to your `.env` file:

```bash
# Admin Configuration
ADMIN_TELEGRAM_IDS=123456789,987654321
ADMIN_USERNAMES=admin_user1,admin_user2
APP_VERSION=1.0.0
```

### 2. Register Components

Update `config/app.php`:

```php
'providers' => [
    // ... existing providers ...
    App\Providers\AdminServiceProvider::class,
],
```

Update `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... existing middleware ...
    'admin' => \App\Http\Middleware\AdminMiddleware::class,
];
```

### 3. Run Setup Command

```bash
php artisan admin:setup
```

### 4. Create Admin User

```bash
php artisan admin:create-user YOUR_TELEGRAM_ID --username=your_username --first-name="Your Name"
```

## Command Line Administration

### Dashboard Commands

#### View System Overview

```bash
php artisan admin:dashboard
```

#### View Specific Statistics

```bash
php artisan admin:dashboard --users    # User statistics
php artisan admin:dashboard --posts    # Post statistics
php artisan admin:dashboard --groups   # Group statistics
php artisan admin:dashboard --errors   # Recent errors
```

### User Management Commands

#### List Users

```bash
php artisan admin:users list
php artisan admin:users list --search="john"
php artisan admin:users list --plan=pro
php artisan admin:users list --limit=50
```

#### View User Details

```bash
php artisan admin:users show --id=USER_ID
php artisan admin:users show --username=john_doe
php artisan admin:users show --telegram-id=123456789
```

#### User Actions

```bash
# Ban/Unban users
php artisan admin:users ban --id=USER_ID
php artisan admin:users unban --id=USER_ID

# Promote/Demote admin privileges
php artisan admin:users promote --id=USER_ID
php artisan admin:users demote --id=USER_ID

# Reset user usage
php artisan admin:users reset-usage --id=USER_ID

# Delete user (destructive!)
php artisan admin:users delete --id=USER_ID --force
```

### System Management Commands

#### Health Check

```bash
php artisan admin:system health
```

#### System Cleanup

```bash
php artisan admin:system cleanup --days=30
php artisan admin:system cleanup --days=7 --dry-run
php artisan admin:system cleanup --force
```

#### Maintenance Operations

```bash
php artisan admin:system maintenance  # Run full maintenance
php artisan admin:system backup       # Create system backup
```

### Monitoring Commands

#### Monitor Scheduler

```bash
php artisan scheduler:monitor
```

#### Verify Admin Statuses

```bash
php artisan admin:verify --all
php artisan admin:verify --stale-only
php artisan admin:verify --user-id=USER_ID
```

#### Refresh Group Information

```bash
php artisan groups:refresh-info --all
php artisan groups:refresh-info --stale-only
php artisan groups:refresh-info --group-id=GROUP_ID
```

## API Administration

All admin API endpoints require authentication and admin permissions.

### Authentication

Include JWT token in Authorization header:

```
Authorization: Bearer YOUR_JWT_TOKEN
```

### Dashboard & Analytics

#### Get System Dashboard

```http
GET /api/admin/dashboard
```

#### Get System Health

```http
GET /api/admin/health
```

#### Get Analytics

```http
GET /api/admin/analytics?period=30
```

### User Management

#### List Users

```http
GET /api/admin/users?search=john&plan=pro&page=1&per_page=20
```

#### Get User Details

```http
GET /api/admin/users/{id}
```

#### Update User

```http
PUT /api/admin/users/{id}
Content-Type: application/json

{
    "subscription": {
        "plan": "pro"
    },
    "settings": {
        "is_admin": true
    }
}
```

#### User Actions

```http
POST /api/admin/users/{id}/ban
POST /api/admin/users/{id}/unban
POST /api/admin/users/{id}/promote
POST /api/admin/users/{id}/demote
POST /api/admin/users/{id}/reset-usage
DELETE /api/admin/users/{id}
```

### Post Management

#### List Posts

```http
GET /api/admin/posts?status=pending&user_id=123&date_from=2024-01-01
```

#### Get Post Details

```http
GET /api/admin/posts/{id}
```

#### Post Actions

```http
DELETE /api/admin/posts/{id}        # Force delete
POST /api/admin/posts/{id}/cancel   # Cancel scheduled post
POST /api/admin/posts/{id}/retry    # Retry failed post
```

### Group Management

#### List Groups

```http
GET /api/admin/groups?search=channel&per_page=20
```

#### Get Group Details

```http
GET /api/admin/groups/{id}
```

#### Group Actions

```http
POST /api/admin/groups/{id}/refresh  # Refresh group info
DELETE /api/admin/groups/{id}        # Delete group
GET /api/admin/groups/{id}/users     # Get group users
```

### Logs & Monitoring

#### Get System Logs

```http
GET /api/admin/logs?status=failed&date_from=2024-01-01
```

#### Get Error Logs

```http
GET /api/admin/logs/errors?group_id=123
```

#### Cleanup Logs

```http
DELETE /api/admin/logs/cleanup
Content-Type: application/json

{
    "days": 30
}
```

### System Operations

#### Maintenance Mode

```http
POST /api/admin/system/maintenance
Content-Type: application/json

{
    "enabled": true,
    "message": "System maintenance in progress"
}
```

#### System Cleanup

```http
POST /api/admin/system/cleanup
Content-Type: application/json

{
    "cleanup_logs": true,
    "cleanup_media": true,
    "cleanup_posts": false,
    "days": 30
}
```

#### Create Backup

```http
POST /api/admin/system/backup
```

### Statistics

#### User Statistics

```http
GET /api/admin/stats/users
```

#### Post Statistics

```http
GET /api/admin/stats/posts
```

#### Revenue Statistics

```http
GET /api/admin/stats/revenue
```

#### Performance Statistics

```http
GET /api/admin/stats/performance
```

## Permission System

The admin system uses Laravel Gates with the following permissions:

-   `admin-access`: Basic admin access
-   `manage-users`: User management operations
-   `manage-posts`: Post management operations
-   `manage-groups`: Group management operations
-   `view-system-stats`: Access to system statistics

### Defining Admins

Admins can be defined in multiple ways:

1. **Environment Variables** (recommended):

    ```bash
    ADMIN_TELEGRAM_IDS=123456789,987654321
    ADMIN_USERNAMES=admin1,admin2
    ```

2. **User Settings**:

    ```php
    $user->settings['is_admin'] = true;
    ```

3. **Database Query** (in AdminServiceProvider):
    ```php
    private function isAdmin(User $user): bool
    {
        // Custom logic here
        return $user->email === 'admin@example.com';
    }
    ```

## Automated Tasks

Add these to your cron schedule for automated maintenance:

```bash
# Every minute - process scheduled posts
* * * * * php artisan posts:process-scheduled

# Every 5 minutes - monitor scheduler
*/5 * * * * php artisan scheduler:monitor

# Every 4 hours - verify stale admin relationships
0 */4 * * * php artisan admin:verify --stale-only

# Daily at 2 AM - full admin verification
0 2 * * * php artisan admin:verify --all

# Every 6 hours - refresh stale group info
0 */6 * * * php artisan groups:refresh-info --stale-only

# Daily - update currency rates
0 0 * * * php artisan currencies:update

# Weekly - system cleanup
0 3 * * 0 php artisan admin:system cleanup --days=30 --force
```

## Security Considerations

1. **Admin Access**: Always verify admin users before granting permissions
2. **API Security**: All admin endpoints require valid JWT tokens
3. **Action Logging**: Consider implementing action logging for audit trails
4. **Rate Limiting**: Apply rate limiting to admin endpoints
5. **IP Whitelisting**: Consider restricting admin access to specific IPs

## Monitoring & Alerts

### Key Metrics to Monitor

1. **Error Rate**: Failed message sending rate
2. **Queue Health**: Stuck or failed queue jobs
3. **Storage Usage**: Media file storage consumption
4. **Database Performance**: Query performance and size
5. **API Response Times**: Endpoint performance

### Setting Up Alerts

Create custom monitoring commands:

```bash
php artisan make:command MonitorAlerts
```

Example alert conditions:

-   Error rate > 5% in last hour
-   Queue has jobs older than 1 hour
-   Storage usage > 80%
-   Database response time > 1 second

## Troubleshooting

### Common Issues

1. **Permission Denied**: Check admin configuration in .env
2. **Command Not Found**: Run `composer dump-autoload`
3. **Database Errors**: Verify MongoDB connection
4. **Queue Issues**: Check queue worker status

### Debug Commands

```bash
# Test admin access
php artisan admin:users show --id=YOUR_USER_ID

# Check system health
php artisan admin:system health

# Verify permissions
php artisan route:list | grep admin
```

## Best Practices

1. **Regular Backups**: Schedule automated backups
2. **Log Monitoring**: Regularly check error logs
3. **Performance Monitoring**: Monitor key metrics
4. **Security Audits**: Regular permission reviews
5. **Documentation**: Keep admin procedures documented
6. **Testing**: Test admin functions in staging environment

## API Response Examples

### Success Response

```json
{
    "message": "Operation completed successfully",
    "data": { ... },
    "timestamp": "2024-01-01T12:00:00Z"
}
```

### Error Response

```json
{
    "error": "Permission denied",
    "message": "You do not have admin access",
    "code": 403
}
```

### Dashboard Response

```json
{
    "users": {
        "total": 1250,
        "active_last_7_days": 340,
        "new_this_month": 45
    },
    "posts": {
        "total": 2847,
        "pending": 23,
        "completed": 2801,
        "failed": 23
    },
    "messages": {
        "total_sent": 15420,
        "success_rate": 98.5
    }
}
```

This admin system provides comprehensive control over your Telegram Scheduler application with both command-line and API interfaces for maximum flexibility.
🚀 Key Components

1. Admin Service Provider

Defines admin gates and permissions
Configurable admin identification (Telegram IDs, usernames, or database flags)

2. Admin Middleware

Protects admin routes with permission-based access control
Supports different permission levels (admin-access, manage-users, etc.)

3. Admin Controller

Complete REST API for admin operations
Dashboard, analytics, user management, system operations
Comprehensive statistics and monitoring

4. Console Commands

AdminDashboard - System overview and statistics
AdminUserManager - User management operations
AdminSystemManager - System maintenance and health checks
CreateAdminUser - Helper to create admin accounts
AdminSetup - Initial setup verification

5. Admin Routes

Organized API endpoints with proper middleware protection
RESTful design with clear permission boundaries

🛠️ Features Included
User Management

List, view, ban/unban users
Promote/demote admin privileges
Reset usage limits
Complete user deletion with cleanup

System Monitoring

Real-time dashboard with key metrics
Health checks for database, Telegram bot, storage, queue
Error rate monitoring and alerting
Performance statistics

Post & Group Management

Administrative post operations (cancel, retry, force delete)
Group information management and refresh
Bulk operations and cleanup tools

Maintenance Operations

Automated cleanup of old logs and media
System backup and restore capabilities
Maintenance mode toggle
Database optimization

Analytics & Reporting

User growth and engagement metrics
Revenue tracking and analysis
Message volume and success rates
Export capabilities for all data types

🎯 Quick Setup

Add to .env:

bashADMIN_TELEGRAM_IDS=your_telegram_id_here
ADMIN_USERNAMES=your_username_here

Run setup commands:

bashphp artisan admin:setup
php artisan admin:create-user YOUR_TELEGRAM_ID

Access admin features:

Command line: php artisan admin:dashboard
API: GET /api/admin/dashboard (with JWT token)

📊 Usage Examples
Command Line:
bash# View system overview
php artisan admin:dashboard

# Manage users

php artisan admin:users list --plan=pro
php artisan admin:users ban --id=123

# System maintenance

php artisan admin:system health
php artisan admin:system cleanup --days=30
API Endpoints:
httpGET /api/admin/dashboard
GET /api/admin/users?search=john&plan=pro
POST /api/admin/users/123/ban
GET /api/admin/stats/revenue
This admin system provides enterprise-level administration capabilities with both CLI and web-based interfaces, comprehensive monitoring, and robust security controls. It's designed to scale with your application and provides all the tools needed to effectively manage your Telegram Scheduler platform.
