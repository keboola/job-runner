#!/usr/bin/env bash
set -e

php /code/bin/console app:cleanup 1>&/proc/1/fd/1
