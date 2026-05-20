#!/usr/bin/env bash
set -e

APP_DIR="/opt/petra_iam_big_project/my_ldap_dashboard"
NAMESPACE="petra-iam"
SECRET_NAME="my-ldap-dashboard-env"
ENV_FILE=".env.k8s"

cd "$APP_DIR"

microk8s kubectl -n "$NAMESPACE" delete secret "$SECRET_NAME" --ignore-not-found

microk8s kubectl -n "$NAMESPACE" create secret generic "$SECRET_NAME" \
  --from-env-file="$ENV_FILE"

echo "Secret $SECRET_NAME updated from $ENV_FILE."
