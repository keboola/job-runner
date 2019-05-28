# Job Queue Daemon [![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status/keboola.job-runner?branchName=master)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=master)

Symfony console application which is used inside an ECS task and wraps Docker runner library.

## Development
Use `docker-composer run tests-local` to get development environment.
To configure Debugger in PHPStorm, point PHPStorm to phpunit.phar in `bin\.phpunit\phpunit-6.5\phpunit`.
To recreate the `bin\.phpunit` folder, run `php bin/phpunit`.

Create a services stack using `provisioning\job-runner.json` CF template if not present.
Create a testing stack using `test-cf-stack.json` CF template. Go to the `JobRunnerUser` created in Resources and create new Access Key for the user.

- modify `.env` file to set `kms_key_id` `logs_s3_bucket` to the created ones 
- `docker-compose build`
- Set environment variables `KBC_TEST_TOKEN` + `KBC_TEST_URL` obtained from a testing KBC project and `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` obtained from the above created user.
- `docker-compose run tests`

## Run Tests

`docker-compose run tests`

