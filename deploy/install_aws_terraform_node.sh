#!/bin/bash
# DI PARMA — AWS CLI, Terraform 1.6.0, Node.js 18 (Ubuntu x86_64)
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo: sudo bash deploy/install_aws_terraform_node.sh"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y unzip curl wget ca-certificates gnupg

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT
cd "$WORKDIR"

# AWS CLI v2
if ! command -v aws >/dev/null 2>&1; then
  curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
  unzip -q awscliv2.zip
  ./aws/install
else
  echo "AWS CLI already installed"
fi

# Terraform 1.6.0
if ! command -v terraform >/dev/null 2>&1; then
  wget -q "https://releases.hashicorp.com/terraform/1.6.0/terraform_1.6.0_linux_amd64.zip"
  unzip -q terraform_1.6.0_linux_amd64.zip
  mv terraform /usr/local/bin/terraform
  chmod +x /usr/local/bin/terraform
else
  echo "Terraform already installed"
fi

# Node.js 18
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
  apt-get install -y nodejs
else
  echo "Node.js already installed"
fi

echo "---- versions ----"
aws --version
terraform --version
node --version
npm --version
