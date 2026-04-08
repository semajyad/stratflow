#!/bin/bash
set -e

PORT="${PORT:-80}"

# Configure Apache port
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/:80/:$PORT/" /etc/apache2/sites-available/000-default.conf

# Wait for MySQL (up to 60 seconds)
echo "Waiting for MySQL..."
for i in $(seq 1 30); do
    if php scripts/init-db.php 2>/dev/null; then
        echo "Database ready."
        break
    fi
    echo "  attempt $i/30..."
    sleep 2
done

echo "Starting Apache on port $PORT..."
exec apache2-foreground
