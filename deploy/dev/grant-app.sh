#!/bin/bash
# (Ré)applique les droits du compte SQL de l'application, tt_app, sur toutes les tables du MPD.
# Usage : sudo deploy/dev/grant-app.sh
#
# À relancer après une migration qui crée une table : le compte tt_migrate crée les tables,
# mais il n'a pas le droit GRANT et ne peut donc pas donner de droits à tt_app (moindre privilège, R1 §4).
# Le script ne crée aucun compte et ne touche à aucun mot de passe : il peut être relancé sans risque.
#
#  - tables métier : SELECT, INSERT, UPDATE — jamais DELETE, DROP, ALTER ni GRANT ;
#  - historique_statut et journal_audit : SELECT, INSERT seulement (ajout seul, ENF-16).
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "À lancer avec sudo." >&2
    exit 1
fi

BASE=tickettournelle
TABLES_METIER="categorie salle equipement utilisateur ticket commentaire piece_jointe notification jeton_compte code_secours"
TABLES_AJOUT_SEUL="historique_statut journal_audit"

for H in localhost 127.0.0.1; do
    for T in $TABLES_METIER; do
        mariadb -e "GRANT SELECT, INSERT, UPDATE ON ${BASE}.${T} TO 'tt_app'@'${H}'"
    done
    for T in $TABLES_AJOUT_SEUL; do
        mariadb -e "GRANT SELECT, INSERT ON ${BASE}.${T} TO 'tt_app'@'${H}'"
    done
done

echo "Droits de tt_app@localhost (la ligne USAGE, qui contient l'empreinte du mot de passe, est masquée) :"
mariadb -N -e "SHOW GRANTS FOR 'tt_app'@'localhost'" | grep -v 'GRANT USAGE'
