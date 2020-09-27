#!/usr/bin/env bash
set -e

export KEBOOLA_STACK=job-runner-test
export REGION=us-east-1
export AWS_ACCESS_KEY_ID=
export AWS_SECRET_ACCESS_KEY=

export CPU_COUNT=1
export KMS_KEY=key id or alias
export LEGACY_OAUTH_API_URL=https://syrup.keboola.com/oauth-v2/
export LOGS_S3_BUCKET=s3bucket
export STORAGE_API_URL=https://connection.keboola.com/
export JOB_QUEUE_URL=https://localhost:81
export JOB_QUEUE_TOKEN=dummy

export TEST_STORAGE_TOKEN=
export SP_PASSWORD
export SP_APP_ID
