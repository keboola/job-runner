#!/bin/bash
set -Eeuo pipefail

if [ -z "${APP_ENV}" ]; then
  echo "Environment variable APP_ENV must be set"
  exit 1
fi

echo "=== Preparing application for ${APP_ENV} environment"

# finish install scripts
composer run post-install-cmd

echo "=== Setup done, starting application"
exec docker-php-entrypoint "$@"
