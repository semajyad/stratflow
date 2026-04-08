#!/bin/bash
set -e

PORT="${PORT:-80}"

# Configure Apache port
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf

# Wait for MySQL and init schema (up to 60 seconds)
echo "Waiting for MySQL..."
for i in $(seq 1 30); do
    if php scripts/init-db.php 2>&1; then
        echo "Database initialized."
        break
    fi
    echo "  MySQL not ready, attempt $i/30..."
    sleep 2
done

echo "Starting Apache on port $PORT..."
exec apache2-foreground
