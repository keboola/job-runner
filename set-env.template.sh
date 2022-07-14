#!/usr/bin/env bash
set -e

export CPU_COUNT=1
export LEGACY_OAUTH_API_URL=https://syrup.keboola.com/oauth-v2/
export JOB_QUEUE_URL=http://internal-api:80
export JOB_QUEUE_TOKEN=dummy
export STORAGE_API_URL=https://connection.keboola.com/
export LEGACY_ENCRYPTION_KEY=

# AWS Stuff
export AWS_REGION=us-east-1
export AWS_KMS_KEY_ID=
export AWS_LOGS_S3_BUCKET=

# Azure stuff
export AZURE_KEY_VAULT_URL=https://testing-job-runner.vault.azure.net
export AZURE_LOG_ABS_CONNECTION_STRING=
export AZURE_LOG_ABS_CONTAINER=

# Testing Stuff
export TEST_STORAGE_API_TOKEN=
export TEST_INTERNAL_API_APPLICATION_TOKEN=
export TEST_AWS_ACCESS_KEY_ID=
export TEST_AWS_SECRET_ACCESS_KEY=

export TEST_AZURE_CLIENT_ID=
export TEST_AZURE_CLIENT_SECRET=
export TEST_AZURE_TENANT_ID=
