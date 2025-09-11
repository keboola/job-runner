terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.11"
    }

    azurerm = {
      source  = "hashicorp/azurerm"
      version = "~> 4.3.0"
    }

    azuread = {
      source  = "hashicorp/azuread"
      version = "~> 2.53.1"
    }

    google = {
      source  = "hashicorp/google"
      version = "~> 4.74.0"
    }
  }

  backend "s3" {
    assume_role = {
      role_arn = "arn:aws:iam::681277395786:role/kbc-local-dev-terraform"
    }
    region         = "eu-central-1"
    bucket         = "local-dev-terraform-bucket"
    dynamodb_table = "local-dev-terraform-table"
  }
}

locals {
  app_name         = "job-runner"
  app_display_name = "Job Runner"
}

variable "name_prefix" {
  type = string
  validation {
    condition     = length(var.name_prefix) > 0
    error_message = "The \"name_prefix\" must be non-empty string."
  }
}
