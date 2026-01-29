# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Job Runner is a Symfony console application that runs inside ECS tasks and wraps the Keboola Docker runner library. It executes data pipeline jobs by pulling job definitions from the Job Queue Internal API, running Docker containers with the appropriate components, and reporting results back to the queue.

## Development Commands

All commands are executed via Docker Compose to maintain consistency with production environment:

```bash
# Install dependencies
docker compose run --rm dev composer install

# Run all CI checks (validation, code style, static analysis, tests)
docker compose run --rm dev composer ci

# Individual checks
docker compose run --rm dev composer phpcs          # Code style check
docker compose run --rm dev composer phpcbf         # Auto-fix code style
docker compose run --rm dev composer phpstan        # Static analysis
docker compose run --rm dev composer phpunit        # Run all tests

# Run specific test file or method
docker compose run --rm dev vendor/bin/phpunit tests/Functional/DebugModeTest.php
docker compose run --rm dev vendor/bin/phpunit --filter testMethodName

# Run Symfony console commands
docker compose run --rm dev bin/console <command>

# Access development container shell
docker compose run --rm dev bash
```

**Note on PHPStan:** The application uses environment-specific PHPStan configuration:
- `phpstan-dev.neon` for development
- `phpstan-test.neon` for testing
- Configuration is selected via `APP_ENV` environment variable

## Architecture Overview

### Core Flow

1. **Job Retrieval**: `RunCommand` pulls a job from Job Queue Internal API using `QueueClient`
2. **Job Definition Creation**: `JobDefinitionFactory` transforms the job into `JobDefinition` objects:
   - Fetches component specification from Storage API
   - Resolves shared code and variables via `VariablesResolver`
   - Decrypts configuration using `ObjectEncryptor`
3. **Execution**: `Runner` (from `keboola/dockerbundle`) executes the job in Docker containers
4. **Result Posting**: Results and metrics are posted back to Job Queue Internal API

### Key Components

- **RunCommand** (`src/Command/RunCommand.php`): Main entry point, orchestrates job execution lifecycle
  - Handles SIGTERM/SIGINT signals for graceful termination
  - Manages job state transitions (waiting → processing → success/error)
  - Coordinates container termination and cleanup

- **JobDefinitionFactory** (`src/JobDefinitionFactory.php`): Creates job definitions from queue jobs
  - Validates branch-specific restrictions (e.g., sandboxes blocked on default branch)
  - Resolves configuration variables and shared code
  - Handles configuration encryption/decryption

- **JobDefinitionParser** (`src/JobDefinitionParser.php`): Parses configurations into executable definitions
  - Supports both single configs and row-based configurations
  - Validates processor placement (config-level vs row-level)

- **Helper Classes**:
  - `ExceptionConverter`: Converts exceptions to `JobResult` objects
  - `OutputResultConverter`: Transforms Docker runner outputs into job results and metrics
  - `BuildBranchClientOptionsHelper`: Constructs Storage API client options from job metadata

### External Dependencies

- **keboola/dockerbundle**: Core Docker container orchestration library
- **keboola/job-queue-internal-api-php-client**: Job queue communication
- **keboola/storage-api-client**: Keboola Storage API interaction
- **keboola/object-encryptor**: Configuration encryption/decryption
- **keboola/configuration-variables-resolver**: Variable and shared code resolution

### Environment Configuration

The app follows Symfony's `.env` pattern:
- `.env` - Versioned defaults for local development
- `.env.local` - Not versioned, for local overrides (create manually)
- `.env.test` - Versioned test environment config
- Production uses environment variables directly (not `.env` files)

Key environment variables:
- `JOB_ID`: Job identifier passed from ECS task
- `STORAGE_API_TOKEN`: Token for Storage API access
- `JOB_QUEUE_URL` / `JOB_QUEUE_TOKEN`: Job Queue Internal API connection
- `AWS_KMS_KEY_ID`, `AZURE_KEY_VAULT_URL`, `GCP_KMS_KEY_ID`: Encryption keys
- `CPU_COUNT`: Instance resource limits

### Branch Handling

Jobs can run on default or development branches:
- Branch type affects configuration safety checks
- Some components (e.g., `keboola.sandboxes`) may be blocked on certain branch types
- Client configuration is branch-aware via `ClientWrapper` and `BranchType` enum

### Testing Strategy

Tests are in `tests/` directory:
- `tests/Command/` - Unit tests for command classes
- `tests/Functional/` - Integration tests with Storage API and Job Queue
- `tests/Helper/` - Unit tests for helper classes

Functional tests require:
- `TEST_STORAGE_API_TOKEN` and `TEST_STORAGE_API_TOKEN_MASTER` in `.env.local`
- Access to Keboola project for testing
- Running `internal-api` service (via docker-compose)

## Development Prerequisites

For full local development (provisioning infrastructure):
- Configured `az`, `aws`, and `gcloud` CLI tools
- Terraform and jq installed
- Access to Keboola Dev environments (AWS, Azure, GCP)
- See README.md "Development" section for detailed setup

## Commit Message Style

Commit messages should NOT include Claude authoring notes or "Co-Authored-By: Claude" attribution.
