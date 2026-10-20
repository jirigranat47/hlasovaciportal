FROM php:8.4-apache

# Install system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure intl \
    && docker-php-ext-install soap pdo_mysql opcache zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Optimize OPcache and realpath cache for fast performance in Docker on Windows
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Apache DocumentRoot to point to Nette www/ directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/www
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Pre-build vendor dependencies into /opt/composer for fast native Linux volume population
COPY composer.json composer.lock /opt/composer/
RUN composer install --working-dir=/opt/composer --no-interaction --no-progress --optimize-autoloader

# Entrypoint to populate /var/www/html/vendor volume and ensure temp permissions
RUN { \
    echo '#!/bin/sh'; \
    echo 'set -e'; \
    echo 'mkdir -p /var/www/html/temp /var/www/html/log'; \
    echo 'chmod -R 0777 /var/www/html/temp 2>/dev/null || true'; \
    echo 'if [ ! -f /var/www/html/vendor/autoload.php ]; then'; \
    echo '    cp -a /opt/composer/vendor/. /var/www/html/vendor/'; \
    echo 'fi'; \
    echo 'exec docker-php-entrypoint "$@"'; \
} > /usr/local/bin/app-entrypoint.sh && chmod +x /usr/local/bin/app-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/app-entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80
