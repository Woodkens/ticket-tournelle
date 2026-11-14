#!/bin/bash
# Installe la configuration Nginx + PHP-FPM de développement.
# Usage (depuis la racine du projet) : sudo deploy/dev/install-web.sh
set -euo pipefail

DEV_USER=${SUDO_USER:?"Lancer avec sudo depuis son compte (pas en root direct)"}
PROJECT=$(cd "$(dirname "$0")/../.." && pwd)

sed "s#__USER__#${DEV_USER}#g" "$PROJECT/deploy/dev/php-fpm-tickettournelle.conf" \
    > /etc/php/8.4/fpm/pool.d/tickettournelle.conf
sed "s#__PROJECT__#${PROJECT}#g" "$PROJECT/deploy/dev/nginx-tickettournelle.conf" \
    > /etc/nginx/sites-available/tickettournelle
ln -sf /etc/nginx/sites-available/tickettournelle /etc/nginx/sites-enabled/tickettournelle
rm -f /etc/nginx/sites-enabled/default

# Nginx (www-data) doit pouvoir TRAVERSER le dossier personnel, sans pouvoir le lister
chmod 711 "/home/${DEV_USER}"

nginx -t
systemctl restart php8.4-fpm nginx
echo "OK : http://localhost/"
