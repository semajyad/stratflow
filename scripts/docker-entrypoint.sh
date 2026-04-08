#!/bin/bash
set -e

PORT="${PORT:-80}"

# Configure Apache port
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf

# Initialize DB in background (don't block Apache startup)
(
    echo "Waiting for MySQL..."
    for i in $(seq 1 60); do
        if php scripts/init-db.php 2>&1 | grep -q "complete"; then
            echo "Database initialized successfully."
            exit 0
        fi
        sleep 3
    done
    echo "WARNING: Database initialization failed after 60 attempts."
) &

echo "Starting Apache on port $PORT..."
exec apache2-foreground
