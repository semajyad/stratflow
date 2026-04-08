#!/bin/bash
set -e

# Configure Apache to listen on Railway's PORT (default 80)
PORT="${PORT:-80}"
sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Wait for MySQL to be ready (up to 30 seconds)
echo "Waiting for MySQL..."
for i in $(seq 1 30); do
    if php -r "
        try {
            \$url = getenv('DATABASE_URL');
            if (\$url) {
                \$p = parse_url(\$url);
                new PDO('mysql:host='.\$p['host'].';port='.(\$p['port']??3306), \$p['user']??'root', \$p['pass']??'');
            } else {
                new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:'3306'), getenv('DB_USERNAME')?:'root', getenv('DB_PASSWORD')?:'');
            }
            echo 'OK';
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null | grep -q OK; then
        echo "MySQL is ready."
        break
    fi
    echo "  attempt $i/30..."
    sleep 2
done

# Initialize database schema
echo "Initializing database..."
php scripts/init-db.php || echo "Warning: DB init had errors (may be OK if tables already exist)"

# Start Apache
echo "Starting Apache on port $PORT..."
exec apache2-foreground
