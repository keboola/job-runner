# Job Queue Daemon [![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status/keboola.job-runner?branchName=main)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=main)

Symfony console application which is used inside an ECS task and wraps Docker runner library.

## Running locally
Use `docker-composer run tests-local` to get development environment.
To configure Debugger in PHPStorm, point PHPStorm to phpunit.phar in `bin\.phpunit\phpunit-6.5\phpunit`.
To recreate the `bin\.phpunit` folder, run `php bin/phpunit`.

## Development

### Prepare Images
Create a service principal to download Internal Queue API image and Job Runner Image and login:

    ```bash
	SERVICE_PRINCIPAL_NAME=devel-job-queue-internal-api-pull
	ACR_REGISTRY_ID=$(az acr show --name keboolapes --query id --output tsv --subscription c5182964-8dca-42c8-a77a-fa2a3c6946ea)
	SP_PASSWORD=$(az ad sp create-for-rbac --name http://$SERVICE_PRINCIPAL_NAME --scopes $ACR_REGISTRY_ID --role acrpull --query password --output tsv)
	SP_APP_ID=$(az ad sp show --id http://$SERVICE_PRINCIPAL_NAME --query appId --output tsv)	
    ```


Add the repository credentials to the k8s cluster:

    ```bash
    kubectl create secret docker-registry regcred --docker-server="https://keboolapes.azurecr.io" --docker-username="$SP_APP_ID" --docker-password="$SP_PASSWORD" --namespace dev-job-runner
    kubectl patch serviceaccount default -p "{\"imagePullSecrets\":[{\"name\":\"regcred\"}]}" --namespace dev-job-runner
    ```

Login and pull the image:

    ```bash
	docker login keboolapes.azurecr.io --username $SP_APP_ID --password $SP_PASSWORD
	docker pull keboolapes.azurecr.io/job-queue-internal-api:latest
    ```

- Set the following environment variables in `set-env.sh` file (use `set-env.template.sh` as sample):
    - `STORAGE_API_URL` - Keboola Connection URL.
    - `TEST_STORAGE_API_TOKEN` - Token to a test project.
    - `TEST_STORAGE_API_TOKEN_MASTER` - Master token to the same project.  
    - `LEGACY_ENCRYPTION_KEY` - Arbitrary 16 character string.

### AWS Setup
- Create a user (`JobRunnerUser`) for local development using the `provisioning/dev/aws.json` CF template. 
    - Create AWS key for the created user. 
    - Set the following environment variables in `.env` file (use `.env.dist` as sample):
        - `TEST_AWS_ACCESS_KEY_ID` - The created security credentials for the `JobRunnerUser` user.
        - `TEST_AWS_SECRET_ACCESS_KEY` - The created security credentials for the `JobRunnerUser` user.
        - `AWS_REGION` - `Region` output of the above stack.
        - `AWS_KMS_KEY_ID` - `KmsKey` output of the above stack.
        - `AWS_LOGS_S3_BUCKET` - `S3LogsBucket` output of the above stack.

### Azure Setup

- Create a resource group:
    ```bash
    az account set --subscription "Keboola DEV PS Team CI"
    az group create --name testing-job-runner --location "East US"
    ```

- Create a service principal:
    ```bash
    az ad sp create-for-rbac --name testing-job-runner
    ```

- Use the response to set values `TEST_AZURE_CLIENT_ID`, `TEST_AZURE_CLIENT_SECRET` and `TEST_AZURE_TENANT_ID` in the `set-env.sh` file:
    ```json 
    {
      "appId": "268a6f05-xxxxxxxxxxxxxxxxxxxxxxxxxxx", //-> TEST_AZURE_CLIENT_ID
      "displayName": "testing-job-runner",
      "name": "http://testing-job-runner",
      "password": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", //-> TEST_AZURE_CLIENT_SECRET
      "tenant": "9b85ee6f-xxxxxxxxxxxxxxxxxxxxxxxxxxx" //-> TEST_AZURE_TENANT_ID
    }
    ```

- Get ID of the service principal:
    ```bash
    SERVICE_PRINCIPAL_ID=$(az ad sp list --display-name testing-job-runner --query "[0].objectId" --output tsv)
    ```

- Get ID of a group to which the current user belongs (e.g. "Developers"):
    ```bash
    GROUP_ID=$(az ad group list --display-name "Developers" --query "[0].objectId" --output tsv)
    ```

- Deploy the key vault and log container. Provide tenant ID, service principal ID and group ID from the previous commands:
    ```bash
    az deployment group create --resource-group testing-job-runner --template-file provisioning/dev/azure.json --parameters vault_name=testing-job-runner tenant_id=9b85ee6f-4fb0-4a46-8cb7-4dcc6b262a89 service_principal_object_id=$SERVICE_PRINCIPAL_ID group_object_id=$GROUP_ID storage_account_name=testingjobrunner container_name=debug-files
    az keyvault show --name testing-job-runner --query "properties.vaultUri"
    ```

    and use the return value to set the value in `set-env.sh` file:
    - `AZURE_KEY_VAULT_URL` - https://testing-job-runner.vault.azure.net

    Go to the [Azure Portal](https://portal.azure.com/) - Storage Account - Access Keys and copy connection string. 
    Go to Storage Account - Lifecycle Management - and set a cleanup rule to remove files older than 1 day from the container.
    Set  `AZURE_LOG_ABS_CONNECTION_STRING` and `AZURE_LOG_ABS_CONTAINER`.

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

### Run tests
Init the local environment:

    docker-compose build
    source ./set-env.sh && docker-compose run tests-local composer install

To run tests locally, set the environment variables and execute:

    source ./set-env.sh && docker-compose run --rm tests composer ci

## License

MIT licensed, see [LICENSE](./LICENSE) file.
