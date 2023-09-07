resource "azurerm_key_vault" "job_runner" {
  name                = "${var.name_prefix}-${local.app_name}"
  tenant_id           = data.azurerm_client_config.current.tenant_id
  resource_group_name = azurerm_resource_group.job_runner.name
  location            = azurerm_resource_group.job_runner.location
  sku_name            = "standard"

  access_policy {
    tenant_id = data.azurerm_client_config.current.tenant_id
    object_id = azuread_service_principal.job_runner.id

    key_permissions = [
      "Decrypt",
      "Encrypt",
    ]

    secret_permissions = [
      "Get",
      "List",
      "Set",
    ]
  }
}

output "az_key_vault_name" {
  value = azurerm_key_vault.job_runner.name
}

output "az_key_vault_url" {
  value = azurerm_key_vault.job_runner.vault_uri
}
