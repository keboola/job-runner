locals {
  az_tenant_id = "9b85ee6f-4fb0-4a46-8cb7-4dcc6b262a89" # Keboola
  az_location  = "West Europe"
}

provider "azurerm" {
  features {}
  tenant_id       = local.az_tenant_id
  subscription_id = "9577e289-304e-4165-abe0-91c933200878" # Keboola DEV PS Team CI
}

provider "azuread" {
  tenant_id = local.az_tenant_id
}

data "azurerm_client_config" "current" {}
data "azuread_client_config" "current" {}

// service principal
resource "azuread_application" "job_runner" {
  display_name = "${var.name_prefix}-${local.app_name}"
  owners       = [data.azuread_client_config.current.object_id]
}

resource "azuread_service_principal" "job_runner" {
  application_id = azuread_application.job_runner.application_id
  owners         = [data.azuread_client_config.current.object_id]
}

resource "azuread_service_principal_password" "job_runner" {
  service_principal_id = azuread_service_principal.job_runner.id
}

// resource group
resource "azurerm_resource_group" "job_runner" {
  name     = "${var.name_prefix}-${local.app_name}"
  location = local.az_location
}

resource "azurerm_role_assignment" "job_runner_contributor" {
  scope                = azurerm_resource_group.job_runner.id
  principal_id         = azuread_service_principal.job_runner.id
  role_definition_name = "Contributor"
}

output "az_tenant_id" {
  value = local.az_tenant_id
}

output "az_application_id" {
  value = azuread_application.job_runner.application_id
}

output "az_application_secret" {
  value     = azuread_service_principal_password.job_runner.value
  sensitive = true
}
