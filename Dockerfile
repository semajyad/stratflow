FROM php:8.4-apache
ARG CACHEBUST=1

# Install PHP extensions + enable rewrite
RUN apt-get update && apt-get install -y \
    libzip-dev unzip libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Fix Apache MPM conflict — disable all then enable only prefork
RUN ls /etc/apache2/mods-enabled/mpm_* 2>/dev/null; \
    rm -f /etc/apache2/mods-enabled/mpm_event.* 2>/dev/null; \
    rm -f /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null; \
    ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf 2>/dev/null; \
    ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load 2>/dev/null; \
    ls /etc/apache2/mods-enabled/mpm_*

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

# Fix CRLF line endings
RUN sed -i 's/\r$//' scripts/*.sh 2>/dev/null || true

# Install production deps
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissions
RUN mkdir -p public/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x scripts/docker-entrypoint.sh

EXPOSE 80
CMD ["bash", "scripts/docker-entrypoint.sh"]
