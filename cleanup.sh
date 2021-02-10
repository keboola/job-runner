#!/usr/bin/env bash
set -e

# The redirection is here because the cleanup command is run in a running container with a logging driver.
#  To make sure that the logging driver receives the output, we redirect it to the stdout of the main container process.
php /code/bin/console app:cleanup 1>&/proc/1/fd/1
