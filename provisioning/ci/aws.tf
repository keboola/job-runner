locals {
  aws_region = "eu-central-1"
}

provider "aws" {
  allowed_account_ids = ["480319613404"] # CI-Platform-Services-Team
  region              = local.aws_region

  default_tags {
    tags = {
      KebolaStack = "${var.name_prefix}-${local.app_name}"
      KeboolaRole = local.app_name
    }
  }
}

data "aws_region" "current" {}
data "aws_caller_identity" "current" {}

resource "aws_iam_user" "job_runner" {
  name = "${var.name_prefix}-${local.app_name}"
}

resource "aws_iam_access_key" "job_runner" {
  user = aws_iam_user.job_runner.name
}

resource "aws_iam_user_policy" "job_runner_ecr_pull" {
  user        = aws_iam_user.job_runner.name
  name_prefix = "ecr_access_"

  policy = jsonencode({
    "Version" = "2012-10-17",
    "Statement" = [
      {
        "Action" = [
          "ecr:GetAuthorizationToken",
          "ecr:BatchCheckLayerAvailability",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage",
        ],
        "Effect"   = "Allow"
        "Resource" = "*"
      }
    ]
  })
}

output "aws_region" {
  value = local.aws_region
}

output "aws_access_key_id" {
  value = aws_iam_access_key.job_runner.id
}

output "aws_access_key_secret" {
  value     = aws_iam_access_key.job_runner.secret
  sensitive = true
}
