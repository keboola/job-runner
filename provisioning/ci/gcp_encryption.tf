resource "google_kms_key_ring" "job_runner_encryption" {
  name     = "${var.name_prefix}-${local.app_name}"
  location = local.gcp_region
}

resource "google_kms_crypto_key" "job_runner_encryption" {
  name     = "${var.name_prefix}-${local.app_name}"
  key_ring = google_kms_key_ring.job_runner_encryption.id
  purpose  = "ENCRYPT_DECRYPT"

  lifecycle {
    prevent_destroy = false
  }
}

resource "google_kms_crypto_key_iam_binding" "object_encryptor_iam" {
  crypto_key_id = google_kms_crypto_key.job_runner_encryption.id
  role          = "roles/cloudkms.cryptoKeyEncrypterDecrypter"

  members = [
    google_service_account.job_runner.member,
  ]
}

output "gcp_kms_key_id" {
  value = google_kms_crypto_key.job_runner_encryption.id
}
