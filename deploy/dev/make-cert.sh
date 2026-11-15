#!/bin/bash
# Crée le certificat HTTPS auto-signé du poste de développement (H7 : auto-signé accepté).
# Usage : sudo deploy/dev/make-cert.sh   (appelé aussi par install-web.sh)
#
# Le certificat et sa clé privée sont rangés dans /etc/ssl, HORS du dépôt Git.
# Auto-signé = le chiffrement est le même qu'avec un vrai certificat, mais le navigateur
# ne connaît pas l'émetteur : il affiche un avertissement à accepter une fois.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "À lancer avec sudo." >&2
    exit 1
fi

CRT=/etc/ssl/certs/tickettournelle-dev.crt
KEY=/etc/ssl/private/tickettournelle-dev.key

if [ -f "$CRT" ] && [ -f "$KEY" ]; then
    echo "Certificat déjà présent : $CRT (rien à faire)."
    exit 0
fi

umask 077   # la clé privée n'est lisible que par root dès sa création
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -noenc -days 365 \
    -keyout "$KEY" -out "$CRT" \
    -subj "/CN=localhost/O=Ticket Tournelle - developpement" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=critical,digitalSignature" \
    -addext "extendedKeyUsage=serverAuth"
chmod 600 "$KEY"
chmod 644 "$CRT"   # le certificat est public par nature, seule la clé est secrète

echo "Certificat créé : $CRT (valable 365 jours). Clé privée : $KEY (root seulement)."
