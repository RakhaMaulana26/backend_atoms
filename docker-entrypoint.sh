#!/usr/bin/env sh
set -e

# Run migrations once on container start.
php artisan migrate --force

exec apache2-foreground
