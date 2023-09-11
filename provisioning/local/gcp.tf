locals {
  gcp_project = "kbc-dev-platform-services"
  gcp_region  = "europe-west1"
}

provider "google" {
  project = local.gcp_project
}

resource "google_service_account" "job_runner" {
  account_id   = "${var.name_prefix}-${local.app_name}"
  display_name = "${var.name_prefix} ${local.app_display_name}"
}

resource "google_service_account_key" "job_runner" {
  service_account_id = google_service_account.job_runner.name
  public_key_type    = "TYPE_X509_PEM_FILE"
  private_key_type   = "TYPE_GOOGLE_CREDENTIALS_FILE"
}

output "gcp_private_key" {
  value     = google_service_account_key.job_runner.private_key
  sensitive = true
}
