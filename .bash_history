sudo certbot certonly --manual --preferred-challenges dns   --agree-tos --manual-public-ip-logging-ok   --register-unsafely-without-email   -d stores.printonet.com -d "*.stores.printonet.com"
sudo nginx -t
sudo systemctl reload nginx
openssl x509 -in /etc/letsencrypt/live/stores.printonet.com/fullchain.pem -noout -text | grep -E "DNS:|Subject:"
curl -I https://stores.printonet.com
curl -I https://test.stores.printonet.com
