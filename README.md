# Job Queue Daemon [![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status/keboola.job-runner?branchName=master)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=master)

Symfony console application which is used inside an ECS task and wraps Docker runner library.

## Running locally
Use `docker-composer run tests-local` to get development environment.
To configure Debugger in PHPStorm, point PHPStorm to phpunit.phar in `bin\.phpunit\phpunit-6.5\phpunit`.
To recreate the `bin\.phpunit` folder, run `php bin/phpunit`.

Create the main stack using `provisioning\job-runner.json` CF template if not present. 
Create a user (`JobRunnerUser`) for local development using 
the `test-cf-stack.json` CF template. Use `DeployPolicy` from the main stack. 
Create AWS key for the created user.  Set the following environment variables:

- `KEBOOLA_STACK` - `Stack` output of the above Main stack. Specifically it's a part of path of the AWS Systems Manager (SSM) parameters - `/keboola/$KEBOOLA_STACK/job-runner/`
- `REGION` - `Region` output of the above Main stack.
- `AWS_ACCESS_KEY_ID` - The created security credentials for the `JobRunnerUser` user.
- `AWS_SECRET_ACCESS_KEY` - The created security credentials for the `JobRunnerUser` user.
- `KMS_KEY` - `KmsKey` returned by the test CF stack
- `LEGACY_OAUTH_API_URL` - usually https://syrup.keboola.com/oauth-v2/
- `LOGS_S3_BUCKET` - `S3LogsBucket` returned by the test CF stack
- `STORAGE_API_URL` - Keboola Connection URL - e.g. https://connection.keboola.com/
- `JOB_QUEUE_URL` - URL of development Queue internal API - e.g. https://9v6f8zdu63.execute-api.eu-central-1.amazonaws.com/test/jobs
- `JOB_QUEUE_TOKEN` - Arbitrary non-empty value
- `TEST_STORAGE_TOKEN` - Keboola Connection test token (needed only to run tests)
- `JOB_ID` - Job ID (need only to run the command itself)
- possibly `legacy_encryption_key` (see below)

### legacy_encryption_key
The thing also needs a `legacy_encryption_key` environment variable. There are two options:

- The easy way - set the `legacy_encryption_key` environment variable to arbitrary 16 character string and close your eyes.
- The hard way - create a SSM parameter with the name: `/keboola/NAME_OF_THE_MAIN_STACK/job-runner/legacy_encryption_key` with `SecureString` type and an arbitrary 16 character value. And use the `get-parameters` composer command to download it to the `.env` file.

You can export the variables manually or you can create and fill the file `set-env.sh` as copy of the attached `set-env.template.sh`.

Than you can run tests:

    docker-compose build
    source ./set-env.sh && docker-compose run tests-local composer install

If the above environment variables are set, the `.env` file will be produced from the SSM parameters. 

## Development

## Development
Create a service principal to download Internal Queue API image and login:

	SERVICE_PRINCIPAL_NAME=devel-job-queue-internal-api-pull

	ACR_REGISTRY_ID=$(az acr show --name keboolapes --query id --output tsv --subscription c5182964-8dca-42c8-a77a-fa2a3c6946ea)

	SP_PASSWORD=$(az ad sp create-for-rbac --name http://$SERVICE_PRINCIPAL_NAME --scopes $ACR_REGISTRY_ID --role acrpull --query password --output tsv)
	
	SP_APP_ID=$(az ad sp show --id http://$SERVICE_PRINCIPAL_NAME --query appId --output tsv)

	SP_APP_ID=$(az ad sp show --id http://$SERVICE_PRINCIPAL_NAME --query password --output tsv)

Login and pull the image:

	docker login keboolapes.azurecr.io --username $SP_APP_ID --password $SP_PASSWORD

	docker pull keboolapes.azurecr.io/job-queue-internal-api:latest

To run locally, set the environment variables and execute:
    
    source ./set-env.sh && docker-compose run tests-local

To run tests on live code, execute:

    source ./set-env.sh && docker-compose run --rm tests-local composer install
    source ./set-env.sh && docker-compose run --rm tests-local composer get-parameters
    source ./set-env.sh && docker-compose run --rm tests-local composer ci


