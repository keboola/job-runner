resource "aws_kms_key" "job_runner" {
  description             = "${local.app_name} Encryption Key"
  deletion_window_in_days = 10
}

resource "aws_iam_user_policy" "job_runner_kms_access" {
  user        = aws_iam_user.job_runner.name
  name_prefix = "kms_access_"

  policy = jsonencode({
    "Version"   = "2012-10-17",
    "Statement" = [
      {
        "Sid"    = "UseKMS",
        "Effect" = "Allow",
        "Action" = [
          "kms:Encrypt",
          "kms:Decrypt",
          "kms:ReEncrypt*",
          "kms:GenerateDataKey*",
          "kms:DescribeKey",
        ],
        "Resource" = aws_kms_key.job_runner.arn,
      },
    ]
  })
}

output "aws_kms_key_id" {
  value = aws_kms_key.job_runner.id
}
