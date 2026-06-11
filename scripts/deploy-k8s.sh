#!/usr/bin/env bash
set -e

APP_DIR="/opt/petra_iam_big_project/my_ldap_dashboard"
NAMESPACE="petra-iam"
IMAGE_NAME="localhost:32000/my-ldap-dashboard"
TAG="$(date +%Y%m%d-%H%M%S)"

cd "$APP_DIR"

echo "1. Sync ENV secret..."
bash scripts/sync-env-secret.sh

echo "2. Build image..."
docker build -t "$IMAGE_NAME:$TAG" -t "$IMAGE_NAME:latest" .

echo "3. Push image to MicroK8s registry..."
docker push "$IMAGE_NAME:$TAG"
docker push "$IMAGE_NAME:latest"

echo "4. Apply Kubernetes manifests..."
microk8s kubectl apply -f k8s/my-ldap-dashboard-provisioner-rbac.yaml
microk8s kubectl apply -f k8s/my-ldap-dashboard-deployment.yaml
microk8s kubectl apply -f k8s/my-ldap-dashboard-service.yaml
microk8s kubectl apply -f k8s/my-ldap-dashboard-ingress.yaml

echo "5. Update deployment image..."
microk8s kubectl -n "$NAMESPACE" set image deployment/my-ldap-dashboard \
  my-ldap-dashboard="$IMAGE_NAME:$TAG"

echo "6. Rollout status..."
microk8s kubectl -n "$NAMESPACE" rollout status deployment/my-ldap-dashboard

echo "7. Pods:"
microk8s kubectl -n "$NAMESPACE" get pods -l app=my-ldap-dashboard -o wide

echo "Done."
echo "URL: https://ldap.petra.ac.id/admin"
