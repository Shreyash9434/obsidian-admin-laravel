#!/usr/bin/env sh
set -e

cd /var/www/html

# Avoid stale provider manifest when dependency set changes across dev/prod images.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

exec "$@"
