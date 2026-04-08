FROM php:8.4-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Fix MPM: disable prefork, enable event (or just ensure only one is loaded)
RUN a2dismod mpm_event 2>/dev/null || true \
    && a2dismod mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set document root to public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copy app
WORKDIR /var/www/html
COPY . .

# Fix line endings on shell scripts (Windows CRLF -> LF)
RUN sed -i 's/\r$//' scripts/*.sh 2>/dev/null || true

# Install production deps
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Create uploads directory and set permissions
RUN mkdir -p public/uploads \
    && chown -R www-data:www-data /var/www/html

# Make entrypoint executable
RUN chmod +x scripts/docker-entrypoint.sh

EXPOSE 80

CMD ["scripts/docker-entrypoint.sh"]
