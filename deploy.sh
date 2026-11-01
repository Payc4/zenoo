#!/bin/sh
# Manual escape hatch. A normal deploy needs NOTHING from this file.
#
# In the container model the work this script used to do is split in two, and
# both halves are automatic:
#
#   docker build  — composer install, importmap:install, asset-map:compile,
#                   cache:warmup (see Dockerfile)
#   container start — wait for the database, run migrations, fix volume
#                   ownership, then hand off to supervisor
#                   (see docker/docker-entrypoint.sh)
#
# So there is no post-deployment command to configure in Coolify. This script
# only exists for the times you want to re-run the runtime half by hand without
# restarting the container:
#
#   docker exec <container> sh /var/www/html/deploy.sh
#
# Note what is NOT here: cache:clear. Opcache runs with validate_timestamps=0
# because the code in an image never changes, so deleting the compiled container
# out from under a live PHP-FPM leaves it serving a cache that no longer exists
# on disk. If you ever need to, restart PHP-FPM in the same breath:
#
#   php bin/console cache:clear --env=prod && supervisorctl restart php-fpm
set -e

cd /var/www/html

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Restarting the Messenger worker so it picks up any schema change..."
supervisorctl restart messenger-consume

echo "Done."
supervisorctl status
