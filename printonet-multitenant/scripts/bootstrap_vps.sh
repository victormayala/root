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

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y nginx mariadb-server redis-server unzip curl jq software-properties-common certbot python3-certbot-nginx rsync

add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.2-fpm php8.2-cli php8.2-curl php8.2-mbstring php8.2-xml php8.2-mysql php8.2-zip php8.2-gd php8.2-intl php8.2-bcmath php8.2-soap php8.2-imagick

curl -fsSL https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp

mysql -uroot <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASSWORD}';
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

mkdir -p "${WP_PATH}"
chown -R www-data:www-data "${WP_PATH}"

cat >/etc/nginx/sites-available/printonet.conf <<NGINX
server {
    listen 80;
    server_name ${APP_DOMAIN} *.${APP_DOMAIN};
    root ${WP_PATH};
    index index.php index.html;
    include /etc/nginx/snippets/printonet-security.conf;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    client_max_body_size 64M;
}
NGINX

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp "${SCRIPT_DIR}/../config/nginx/security.conf" /etc/nginx/snippets/printonet-security.conf

ln -sf /etc/nginx/sites-available/printonet.conf /etc/nginx/sites-enabled/printonet.conf
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl enable nginx mariadb redis-server php8.2-fpm
systemctl restart nginx mariadb redis-server php8.2-fpm

echo "Base VPS bootstrap complete."
