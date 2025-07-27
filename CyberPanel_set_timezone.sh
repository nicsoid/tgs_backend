#!/bin/bash
# CyberPanel VPS Timezone Setup Script

echo "=== CyberPanel VPS Timezone Configuration ==="

# 1. Set system timezone to UTC
echo "Setting system timezone to UTC..."
sudo timedatectl set-timezone UTC

# Verify system timezone
echo "Current system timezone:"
timedatectl

# 2. Set PHP timezone for all PHP versions
echo "Configuring PHP timezone..."

# For PHP 8.2 (adjust version as needed)
PHP_INI_PATH="/etc/php/8.2/fpm/php.ini"
PHP_CLI_INI_PATH="/etc/php/8.2/cli/php.ini"

if [ -f "$PHP_INI_PATH" ]; then
    sudo sed -i 's/;date.timezone =/date.timezone = UTC/' $PHP_INI_PATH
    sudo sed -i 's/date.timezone = .*/date.timezone = UTC/' $PHP_INI_PATH
    echo "Updated PHP-FPM timezone configuration"
fi

if [ -f "$PHP_CLI_INI_PATH" ]; then
    sudo sed -i 's/;date.timezone =/date.timezone = UTC/' $PHP_CLI_INI_PATH
    sudo sed -i 's/date.timezone = .*/date.timezone = UTC/' $PHP_CLI_INI_PATH
    echo "Updated PHP CLI timezone configuration"
fi

# For other PHP versions (if you have multiple)
for version in 8.1 8.3; do
    if [ -d "/etc/php/$version" ]; then
        sudo sed -i 's/;date.timezone =/date.timezone = UTC/' /etc/php/$version/fpm/php.ini 2>/dev/null
        sudo sed -i 's/date.timezone = .*/date.timezone = UTC/' /etc/php/$version/fpm/php.ini 2>/dev/null
        sudo sed -i 's/;date.timezone =/date.timezone = UTC/' /etc/php/$version/cli/php.ini 2>/dev/null
        sudo sed -i 's/date.timezone = .*/date.timezone = UTC/' /etc/php/$version/cli/php.ini 2>/dev/null
        echo "Updated PHP $version timezone configuration"
    fi
done

# 3. Restart PHP-FPM services
echo "Restarting PHP-FPM services..."
sudo systemctl restart php8.2-fpm 2>/dev/null || echo "PHP 8.2 FPM not found"
sudo systemctl restart php8.1-fpm 2>/dev/null || echo "PHP 8.1 FPM not found"
sudo systemctl restart php8.3-fpm 2>/dev/null || echo "PHP 8.3 FPM not found"

# 4. Restart web servers
echo "Restarting web servers..."
sudo systemctl restart nginx
sudo systemctl restart apache2 2>/dev/null || echo "Apache not installed"

# 5. Configure MySQL/MariaDB timezone (if applicable)
echo "Configuring MySQL/MariaDB timezone..."
mysql -u root -p -e "SET GLOBAL time_zone = '+00:00';" 2>/dev/null || echo "MySQL configuration skipped (manual setup may be needed)"

# 6. Configure MongoDB timezone (if applicable)
echo "MongoDB uses system timezone by default, should now be UTC"

# 7. Set environment variables for your Laravel app
echo "Setting up Laravel environment variables..."
cd /home/your-domain/public_html  # Adjust path to your Laravel app

# Update .env file
if [ -f ".env" ]; then
    # Remove existing timezone settings
    sed -i '/^APP_TIMEZONE/d' .env
    sed -i '/^DB_TIMEZONE/d' .env
    sed -i '/^LOG_TIMEZONE/d' .env
    
    # Add new timezone settings
    echo "" >> .env
    echo "# Timezone Configuration" >> .env
    echo "APP_TIMEZONE=UTC" >> .env
    echo "DB_TIMEZONE=UTC" >> .env
    echo "LOG_TIMEZONE=UTC" >> .env
    
    echo "Updated .env file with timezone settings"
fi

# 8. Clear Laravel caches
echo "Clearing Laravel caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 9. Set up cron jobs with UTC timezone
echo "Configuring cron jobs..."
(crontab -l 2>/dev/null; echo "# Laravel Scheduler - UTC timezone") | crontab -
(crontab -l 2>/dev/null; echo "* * * * * cd /home/your-domain/public_html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1") | crontab -

# 10. Verify configuration
echo ""
echo "=== Configuration Verification ==="
echo "System timezone: $(timedatectl | grep "Time zone")"
echo "PHP timezone: $(php -r 'echo date_default_timezone_get();')"
echo "Current UTC time: $(date -u)"
echo "Current local time: $(date)"

# Test Laravel timezone
if [ -f "artisan" ]; then
    echo "Laravel timezone test:"
    php artisan debug:timezone 2>/dev/null || echo "Run 'php artisan debug:timezone' manually to test Laravel timezone"
fi

echo ""
echo "=== Important Notes ==="
echo "1. Update your Laravel app path in this script (currently set to /home/your-domain/public_html)"
echo "2. Restart your queue workers: supervisor or pm2"
echo "3. Test your application to ensure timezone conversion works correctly"
echo "4. Monitor logs for any timezone-related issues"

# 11. Restart supervisor (if using for queue workers)
echo "Restarting supervisor..."
sudo systemctl restart supervisor 2>/dev/null || echo "Supervisor not found - restart queue workers manually"

echo "=== Setup Complete ==="