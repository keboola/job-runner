# Job Queue Daemon [![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status/keboola.job-runner?branchName=master)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=master)

Symfony console application which is used inside an ECS task and wraps Docker runner library.

## Development
Use `docker-composer run tests-local` to get development environment.
To configure Debugger in PHPStorm, point PHPStorm to phpunit.phar in `bin\.phpunit\phpunit-6.5\phpunit`.
To recreate the `bin\.phpunit` folder, run `php bin/phpunit`.

## Run Tests

`docker-compose run tests`