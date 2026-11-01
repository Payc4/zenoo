#!/bin/sh
# Messenger worker diagnostics. Run inside the container:
#
#   docker exec <container> check-messenger.sh
#
# The worker now logs to stdout/stderr rather than var/log/messenger.log, so its
# output is in `docker logs <container>` (or the Coolify logs tab) interleaved
# with nginx and PHP-FPM. This script covers the parts that are not in the log:
# whether the process is alive, and what is sitting in the queue.

echo "=== Messenger worker status ==="
supervisorctl status messenger-consume

echo ""
echo "=== Queue depth ==="
php /var/www/html/bin/console messenger:stats

echo ""
echo "=== Failed messages ==="
php /var/www/html/bin/console messenger:failed:show --max=10

echo ""
echo "Worker logs:   docker logs <container> 2>&1 | grep -i messenger"
echo "Restart:       supervisorctl restart messenger-consume"
echo "Retry failed:  php /var/www/html/bin/console messenger:failed:retry"
