<?php

/**
 * @file
 * Local overrides (copy to settings.local.php). Gitignored.
 *
 * Use when running Drush on your Mac against Docker Postgres (port 5433).
 */

declare(strict_types=1);

$databases['default']['default']['host'] = '127.0.0.1';
$databases['default']['default']['port'] = 5433;
