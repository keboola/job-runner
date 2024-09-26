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

  backend "s3" {}
}

locals {
  app_name         = "job-runner"
  app_display_name = "Job Runner"
}

variable "name_prefix" {
  type    = string
  default = "ci"
}
