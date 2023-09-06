# Job Queue Daemon [![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status/keboola.job-runner?branchName=main)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=main)

Symfony console application which is used inside an ECS task and wraps Docker runner library.

## Development
Prerequisites:
* configured `az` and `aws` CLI tools (run `az login` and `aws configure --profile keboola-dev-platform-services`)
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

To run a webserver, hosting your app, use the `dev-server` service:
```shell
docker-compose up dev-server
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




### AWS Setup
- Create a user (`JobRunnerUser`) for local development using the `provisioning/dev/aws.json` CF template. 
    - Create AWS key for the created user. 
    - Set the following environment variables in `.env` file (use `.env.dist` as sample):
        - `TEST_AWS_ACCESS_KEY_ID` - The created security credentials for the `JobRunnerUser` user.
        - `TEST_AWS_SECRET_ACCESS_KEY` - The created security credentials for the `JobRunnerUser` user.
        - `AWS_REGION` - `Region` output of the above stack.
        - `AWS_KMS_KEY_ID` - `KmsKey` output of the above stack.
        - `AWS_LOGS_S3_BUCKET` - `S3LogsBucket` output of the above stack.

### Start Internal API
Copy `dev-environments.yaml.template` to `dev-environments.yaml` and
fill in AWS Key and Credentials with access to the Key and required component images.

Run:
```
kubectl apply -f provisioning/dev/environments.yaml
```

Run
```
kubectl apply -f provisioning/dev/internal-api.yaml
```

This will start the internal API server. It takes a while to start. Check that it runs by executing:

```
curl http://localhost/jobs
```

which should return empty list `[]`.

(provided that the kubernetes cluster runs on localhost)

### Encrypt token

Run:

```
kubectl apply -f provisioning/dev-encrypt.yaml
kubectl wait --for=condition=complete job/job-runner-encrypt --timeout=900s
```

If you get `field is immutable` error run:
```
kubectl delete job/job-runner-encrypt
```

Get the encrypted token value:

```
kubectl logs job/job-runner-encrypt
```

This will return sth like:

```
KBC::Secure::eJwBXXXX
```

Create a job - a minimal configuration:

```
curl --location --request POST 'localhost:80/jobs' \
--header 'Content-Type: application/json' \
--data-raw '{
    "token": {
        "token": "KBC::Secure::eJwBXXXX",
        "id": "165618"
    },
    "project": {
        "id": "6553"
    },
    "id": "133",
    "status": "processing",
    "params": {
        "config": "2797256784",
        "component": "keboola.ex-http",
        "mode": "run"
    }
}'
```

Provided that `config` and `component` are valid. Take care that `id` must be unique and `status` must be `processing`.
The job runner can then use with `http://localhost:80` and `JOB_ID` variable set to the chosen id.

## License

MIT licensed, see [LICENSE](./LICENSE) file.
