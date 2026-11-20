#!/bin/bash
set -euo pipefail

if [ ! -f /opt/drupal/composer.json ]; then
  echo "Seeding Drupal codebase into /opt/drupal..."
  cp -a /usr/src/drupal-seed/. /opt/drupal/
  chown -R www-data:www-data /opt/drupal || true
fi

exec docker-php-entrypoint "$@"
