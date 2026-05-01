#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${1:-.env}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE"
  exit 1
fi

set -a
source "$ENV_FILE"
set +a

if [[ -z "${WP_URL:-}" || -z "${PLATFORM_HMAC_SECRET:-}" ]]; then
  echo "WP_URL and PLATFORM_HMAC_SECRET must be set in ${ENV_FILE}"
  exit 1
fi

REQUEST_ID="smoke-$(date +%s)"
TENANT_SLUG="smoke-$(date +%s)"
BODY="$(cat <<JSON
{
  "request_id": "${REQUEST_ID}",
  "tenant_slug": "${TENANT_SLUG}",
  "store_name": "Smoke Test Store",
  "admin_email": "smoke@example.com",
  "primary_color": "#111111",
  "secondary_color": "#ffffff"
}
JSON
)"

TIMESTAMP="$(date +%s)"
SIGNATURE="$(printf "%s.%s" "$TIMESTAMP" "$BODY" | openssl dgst -sha256 -hmac "$PLATFORM_HMAC_SECRET" -hex | awk '{print $2}')"

echo "Calling ${WP_URL}/wp-json/printonet/v1/provision-store"
echo "tenant_slug=${TENANT_SLUG} request_id=${REQUEST_ID}"

curl -sS -X POST "${WP_URL}/wp-json/printonet/v1/provision-store" \
  -H "Content-Type: application/json" \
  -H "X-Printonet-Timestamp: ${TIMESTAMP}" \
  -H "X-Printonet-Signature: ${SIGNATURE}" \
  --data "$BODY"

echo

DELETE_BODY="$(cat <<JSON
{
  "request_id": "${REQUEST_ID}-delete",
  "tenant_slug": "${TENANT_SLUG}"
}
JSON
)"

TIMESTAMP_DEL="$(date +%s)"
SIGNATURE_DEL="$(printf "%s.%s" "$TIMESTAMP_DEL" "$DELETE_BODY" | openssl dgst -sha256 -hmac "$PLATFORM_HMAC_SECRET" -hex | awk '{print $2}')"

echo "Calling ${WP_URL}/wp-json/printonet/v1/delete-store"
curl -sS -X POST "${WP_URL}/wp-json/printonet/v1/delete-store" \
  -H "Content-Type: application/json" \
  -H "X-Printonet-Timestamp: ${TIMESTAMP_DEL}" \
  -H "X-Printonet-Signature: ${SIGNATURE_DEL}" \
  --data "$DELETE_BODY"

echo
