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

# Fix Apache MPM - ensure only prefork is loaded
RUN a2dismod mpm_event 2>/dev/null; \
    a2dismod mpm_worker 2>/dev/null; \
    a2enmod mpm_prefork; \
    true

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

# Fix CRLF on shell scripts
RUN sed -i 's/\r$//' scripts/*.sh 2>/dev/null || true

# Install production deps
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissions
RUN mkdir -p public/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x scripts/docker-entrypoint.sh

EXPOSE 80

# Entrypoint: configure port, start Apache (DB init runs in background)
CMD ["bash", "scripts/docker-entrypoint.sh"]
