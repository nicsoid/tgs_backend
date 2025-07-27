# backend/Dockerfile - Fixed Version

FROM php:8.2-fpm as base

# Set timezone to UTC at the container level
ENV TZ=UTC
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libssl-dev \
    pkg-config \
    supervisor \
    cron \
    nginx \
    netcat-openbsd \
    tzdata

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip

# Install MongoDB extension
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Development stage
FROM base as development

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-scripts --no-autoloader

# Copy application code
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize

# Create PHP configuration with timezone - FIXED SYNTAX
RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini && \
    echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/app.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/app.ini

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Production stage
FROM base as production

# Copy composer files
COPY composer.json composer.lock ./

# Install production dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --optimize --classmap-authoritative

# Create directories
RUN mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/storage/framework/cache \
    && mkdir -p /var/www/html/storage/framework/sessions \
    && mkdir -p /var/www/html/storage/framework/views \
    && mkdir -p /var/www/html/storage/app/public/media \
    && mkdir -p /var/www/html/bootstrap/cache \
    && mkdir -p /var/log/supervisor

# Create PHP configuration with timezone - FIXED SYNTAX
RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini && \
    echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/app.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/app.ini

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy entrypoint script if it exists
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--verbose"]