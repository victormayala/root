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

if [[ -z "${LETSENCRYPT_EMAIL:-}" ]]; then
  echo "LETSENCRYPT_EMAIL must be set in ${ENV_FILE}"
  exit 1
fi

echo "Requesting certificate for ${APP_DOMAIN} via nginx challenge."
echo "Note: wildcard certs require DNS challenge and are not automatic in this script."

certbot --nginx \
  --agree-tos \
  --email "${LETSENCRYPT_EMAIL}" \
  -d "${APP_DOMAIN}" \
  --non-interactive

echo "To install wildcard certificate, run:"
echo "certbot certonly --manual --preferred-challenges dns -d ${APP_DOMAIN} -d *.${APP_DOMAIN}"
echo "Then update nginx ssl_certificate paths to wildcard cert files."

systemctl reload nginx
echo "TLS setup complete."
