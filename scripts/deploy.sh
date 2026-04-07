#!/bin/bash
# Deploy StratFlow to cPanel shared hosting
# Usage: ./scripts/deploy.sh

set -euo pipefail

REMOTE_HOST="threepointssolutions.com"
REMOTE_USER="your_cpanel_username"
REMOTE_PATH="/home/$REMOTE_USER/stratflow.threepointssolutions.com"

echo "Building for production..."
docker compose exec php composer install --no-dev --optimize-autoloader

echo "Syncing files..."
rsync -avz --delete \
    --exclude='.git' \
    --exclude='.env' \
    --exclude='docker/' \
    --exclude='docker-compose.yml' \
    --exclude='docker/mysql-data/' \
    --exclude='tests/' \
    --exclude='public/uploads/*' \
    --exclude='node_modules/' \
    --exclude='scripts/' \
    ./ "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/"

echo "Deploy complete. Remember to:"
echo "  1. Set up .env on the server"
echo "  2. Import database/schema.sql"
echo "  3. Run php scripts/create-admin.php on the server"
