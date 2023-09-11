# Job Queue Daemon [![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status/keboola.job-runner?branchName=main)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=main)

Symfony console application which is used inside an ECS task and wraps Docker runner library.

## Development
Prerequisites:
* configured `az` and `aws` CLI tools (run `az login` and `aws configure --profile Keboola-CI-Platform-Services-Team-AWSAdministratorAccess`)
* installed `terraform` (https://www.terraform.io) and `jq` (https://stedolan.github.io/jq) to setup local env
* intalled `docker` and `docker-compose` to run & develop the app

TL;DR:
```
export NAME_PREFIX= # your name/nickname to make your resource unique & recognizable

cat <<EOF > ./provisioning/local/terraform.tfvars
name_prefix = "${NAME_PREFIX}"
EOF

cat <<EOF > .env.local
TEST_STORAGE_API_TOKEN= # regular token for your Keboola project
TEST_STORAGE_API_TOKEN_MASTER= # master token for your Keboola project
EOF

terraform -chdir=./provisioning/local init -backend-config="key=job-runner/${NAME_PREFIX}.tfstate"
terraform -chdir=./provisioning/local apply
./provisioning/local/update-env.sh aws # or azure

docker-compose run --rm dev composer install
docker-compose run --rm dev composer ci
```

### Using Docker
Project has Docker development environment setup, so you don't need to install anything on your local computer, except
the Docker & Docker Compose.

To run PHP scripts, use the `dev` service:
```shell
docker-compose run --rm dev composer install   # install dependencies using Composer 
docker-compose run --rm dev composer phpunit   # run Phpunit as a Composer script
docker-compose run --rm dev vendor/bin/phpunit # run Phpunit standalone
docker-compose run --rm dev bin/console        # run Symfony console commands
```

To run local tests, use `ci` service. This will validate `composer` files and execute `phpcs`, `phpstan` and `phpunit` tests.
```shell
docker-compose run --rm ci
```

## ENV & Configuration
For local development, we follow Symfony best practices as described in
[docs](https://symfony.com/doc/current/configuration.html#configuring-environment-variables-in-env-files)
and use `.env` file:
* `.env` is versioned, should contain sane defaults to run the service out of the box locally
* `.env.local` is not versioned, can be created to override any ENV variable locally
* `.env.test` is versioned, should contain anything extra is needed for `test` environment

These are used for local development only and are not included in final Docker images, used to run the app in
production. Instead, we put an empty `.env.local.php` file into Docker, disabling the `.env` functionality and all
configuration must be provided using regular environment variables (like `-e` flag of Docker).

## License

MIT licensed, see [LICENSE](./LICENSE) file.
