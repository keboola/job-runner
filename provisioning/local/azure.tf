locals {
  azure_location = "West Europe"
}

variable "az_tenant_id" {
  type    = string
  default = "9b85ee6f-4fb0-4a46-8cb7-4dcc6b262a89" # Keboola
}

provider "azurerm" {
  features {}
  tenant_id       = var.az_tenant_id
  subscription_id = "c5182964-8dca-42c8-a77a-fa2a3c6946ea" # Keboola DEV Platform Services Team
}

provider "azuread" {
  tenant_id = var.az_tenant_id
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
  location = local.azure_location
}

resource "azurerm_role_assignment" "job_runner_contributor" {
  scope                = azurerm_resource_group.job_runner.id
  principal_id         = azuread_service_principal.job_runner.id
  role_definition_name = "Contributor"
}

output "az_tenant_id" {
  value = var.az_tenant_id
}

output "az_application_id" {
  value = azuread_application.job_runner.application_id
}

output "az_application_secret" {
  value     = azuread_service_principal_password.job_runner.value
  sensitive = true
}
