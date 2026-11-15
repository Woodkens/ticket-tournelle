#!/bin/bash
# Crée la base de TEST et son compte SQL, pour les tests automatiques (php bin/phpunit).
# Usage (depuis la racine du projet) : sudo deploy/dev/create-test-db.sh
#
#  - Base « tickettournelle_test » : copie exacte du MPD (D-06), vide.
#  - Compte « tt_test » : SELECT, INSERT, UPDATE, DELETE sur cette base UNIQUEMENT.
#    Les tests ont besoin de DELETE pour repartir d'une base vide ; le compte de l'application
#    (tt_app) ne l'a pas et ne doit pas l'avoir. tt_test n'a aucun droit sur la vraie base.
#  - Le mot de passe est tiré au hasard, jamais affiché, écrit dans .env.test.local (hors Git, droits 600).
#
# Le script peut être relancé : il recrée la base de test à neuf et change le mot de passe.
set -euo pipefail

DEV_USER=${SUDO_USER:?"Lancer avec sudo depuis son compte (pas en root direct)"}
PROJECT=$(cd "$(dirname "$0")/../.." && pwd)
BASE_TEST=tickettournelle_test

# 1) Le MPD, avec le nom de la base remplacé par celui de la base de test
SQL=$(sed -e "s/^CREATE DATABASE IF NOT EXISTS tickettournelle\$/CREATE DATABASE IF NOT EXISTS ${BASE_TEST}/" \
          -e "s/^USE tickettournelle;\$/USE ${BASE_TEST};/" \
          "$PROJECT/docs/ticket_tournelle_mpd.sql")

# Garde-fou : on ne charge rien si le remplacement n'a pas eu lieu (jamais la vraie base)
if ! grep -q "^USE ${BASE_TEST};\$" <<<"$SQL" || grep -q '^USE tickettournelle;$' <<<"$SQL"; then
    echo "Le MPD n'a pas la forme attendue : arrêt, aucune base modifiée." >&2
    exit 1
fi

mariadb -e "DROP DATABASE IF EXISTS ${BASE_TEST}"
mariadb <<<"$SQL"

# 2) Le compte tt_test, limité à la base de test
PW=$(openssl rand -hex 24)
for H in localhost 127.0.0.1; do
mariadb <<SQL
CREATE USER IF NOT EXISTS 'tt_test'@'$H' IDENTIFIED BY '${PW}';
ALTER USER 'tt_test'@'$H' IDENTIFIED BY '${PW}';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${BASE_TEST}.* TO 'tt_test'@'$H';
SQL
done

# 3) .env.test.local : lu seulement par les tests (Symfony ajoute « _test » au nom de la base en test)
ENV_FILE="$PROJECT/.env.test.local"
umask 077
printf 'DATABASE_URL="mysql://tt_test:%s@127.0.0.1:3306/tickettournelle?serverVersion=11.8.6-MariaDB&charset=utf8mb4"\n' "$PW" > "$ENV_FILE"
unset PW
chown "${DEV_USER}:${DEV_USER}" "$ENV_FILE"
chmod 600 "$ENV_FILE"

echo "OK : base ${BASE_TEST} créée, compte tt_test limité à cette base, .env.test.local écrit (hors Git)."
