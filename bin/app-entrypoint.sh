#!/bin/bash
set -Eeuo pipefail

DATA_VOLUME_HOST_PATH="${DATA_VOLUME_HOST_PATH:-}"
if [ ! -z "${DATA_VOLUME_HOST_PATH}" ]; then
  echo "Setting TMPDIR to \"${DATA_VOLUME_HOST_PATH}\""
  export TMPDIR="${DATA_VOLUME_HOST_PATH}"
fi

exec docker-php-entrypoint "$@"
