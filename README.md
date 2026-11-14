# Ticket'Tournelle — README technique

Portail de déclaration et de suivi d'incidents techniques du lycée La Tournelle (BTS SIO 2 SLAM).
Binôme : William Matondo Ngongo · Woodkens Guervil.

Ce fichier liste **chaque commande d'installation**, dans l'ordre, pour que chaque membre du binôme
puisse refaire le même poste et pour préparer le serveur Debian 13 en S5.
Documents de référence : `docs/` (cahier des charges, R1, R2, MPD, journal de décisions).

> Règle absolue : aucun secret dans ce fichier ni dans Git. Les mots de passe vivent uniquement
> dans `.env.local` et `~/.config/tickettournelle/migrate.env` (droits 600, hors Git),
> générés sur le poste et jamais affichés.

---

## A. Dépôt Git

### A.1 Création (faite une seule fois)

```bash
git init -b main
git add .gitignore .gitattributes README.md CLAUDE.md docs .claude/skills
git commit -m "chore(depot): initialisation du dépôt et de la documentation"
git remote add origin https://github.com/Woodkens/ticket-tournelle.git
git push -u origin main
```

- `.gitignore` est présent **dès le premier commit** : `.env.local`, `/vendor`, `/var`, pièces jointes… (ENF-11).
- `.gitattributes` force les fins de ligne Unix (LF) : sans cela, Windows ajoute des `CR` qui cassent
  les scripts shell et la configuration Nginx sur Debian.
- Dépôt GitHub **privé** `Woodkens/ticket-tournelle`, William y est invité comme collaborateur.

### A.2 Relecture croisée (ENF-32)

- On ne travaille jamais directement sur `main` : une branche `feature/<sujet>` par fonctionnalité.
- Fusion dans `main` uniquement par **Pull Request relue et approuvée par l'autre membre du binôme**.
- **État** : la protection de branche GitHub n'est pas disponible sur un dépôt privé d'un compte gratuit ;
  la règle s'applique donc **par convention**, vérifiable dans l'historique des Pull Requests.
  Si le compte la permet (Settings → Branches → *Add rule* sur `main`, « Require a pull request
  before merging »), elle est activée et cette ligne est mise à jour.

### A.3 Convention de commits

Format `type(portée): description` en français. Types : `feat`, `fix`, `sec`, `test`, `docs`, `chore`.

---

## B. Poste de développement : WSL2 + Debian 13 (D-04)

Même pile que le futur serveur : tout vient des dépôts officiels Debian (ENF-22).

### B.1 Installer Debian dans WSL2 (PowerShell Windows)

```bash
wsl --install -d Debian --no-launch
```

Vérifier la version : `wsl -d Debian -- cat /etc/os-release` → `Debian GNU/Linux 13 (trixie)`.

### B.2 Créer son compte Linux (PowerShell, en root dans WSL)

Remplacer `<moi>` par son identifiant (sans accent, en minuscules).

```bash
wsl -d Debian -u root -- apt-get install -y sudo
wsl -d Debian -u root -- useradd -m -s /bin/bash -G sudo <moi>
wsl -d Debian -u root -- passwd <moi>
```

Puis, dans `/etc/wsl.conf` (en root), ajouter l'utilisateur par défaut :

```ini
[boot]
systemd=true

[user]
default=<moi>
```

Redémarrer WSL : `wsl --shutdown`, puis `wsl -d Debian`.

### B.3 Installer la pile (dans Debian)

```bash
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y git unzip curl ca-certificates nginx mariadb-server \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring php8.4-intl \
  php8.4-zip php8.4-curl php8.4-opcache composer gitleaks
```

Versions obtenues le 14/11/2026 : PHP 8.4.27, MariaDB 11.8.6, Nginx 1.26.3, Composer 2.8.8, gitleaks 8.16.0.

### B.4 Configurer Git et cloner le projet

```bash
git config --global user.name "Prénom Nom"
git config --global user.email "adresse@exemple.fr"
git config --global init.defaultBranch main
git config --global core.autocrlf input
# Connexion GitHub par le navigateur, via le gestionnaire d'identifiants de Git pour Windows :
git config --global credential.helper "/mnt/c/Program\ Files/Git/mingw64/bin/git-credential-manager.exe"

cd ~
git clone https://github.com/Woodkens/ticket-tournelle.git
cd ticket-tournelle
git config core.hooksPath .githooks     # active le hook gitleaks avant chaque push
```

> Le projet doit rester dans `~/ticket-tournelle` (système de fichiers Linux) : sous `/mnt/c`, Symfony est très lent.

### B.5 Base de données et comptes SQL (moindre privilège, R1 §4)

1. Créer la base à partir du MPD (source de vérité du schéma, D-06) :

```bash
sudo mariadb < docs/ticket_tournelle_mpd.sql
```

2. Générer les secrets **sans jamais les afficher** et créer les comptes `tt_app` et `tt_migrate`.
   Le script ci-dessous tire des mots de passe aléatoires, crée les comptes avec exactement les droits
   du MPD, et écrit `.env.local` (application, `tt_app`) et `~/.config/tickettournelle/migrate.env`
   (migrations, `tt_migrate`, hors du projet), en droits 600.

```bash
gen() { head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32; }
APP_SECRET=$(gen); PW_APP=$(gen); PW_MIG=$(gen)

for H in localhost 127.0.0.1; do
sudo mariadb <<SQL
CREATE USER IF NOT EXISTS 'tt_app'@'$H'     IDENTIFIED BY '${PW_APP}';
CREATE USER IF NOT EXISTS 'tt_migrate'@'$H' IDENTIFIED BY '${PW_MIG}';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.categorie    TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.salle        TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.equipement   TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.utilisateur  TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.ticket       TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.commentaire  TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.piece_jointe TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE ON tickettournelle.notification TO 'tt_app'@'$H';
GRANT SELECT, INSERT         ON tickettournelle.historique_statut TO 'tt_app'@'$H';
GRANT SELECT, INSERT         ON tickettournelle.journal_audit     TO 'tt_app'@'$H';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
  ON tickettournelle.* TO 'tt_migrate'@'$H';
SQL
done

umask 077
URL='@127.0.0.1:3306/tickettournelle?serverVersion=11.8.6-MariaDB&charset=utf8mb4'
printf 'APP_SECRET=%s\nDATABASE_URL="mysql://tt_app:%s%s"\n' "$APP_SECRET" "$PW_APP" "$URL" > .env.local
mkdir -p ~/.config/tickettournelle
printf 'DATABASE_URL="mysql://tt_migrate:%s%s"\n' "$PW_MIG" "$URL" > ~/.config/tickettournelle/migrate.env
unset APP_SECRET PW_APP PW_MIG
```

3. Lancer une commande Doctrine avec le compte de migration (jamais l'application) :

```bash
(set -a; . ~/.config/tickettournelle/migrate.env; php bin/console doctrine:migrations:migrate)
```

### B.6 Nginx + PHP-FPM (site de dev sur http://localhost/)

Les fichiers versionnés `deploy/dev/` sont installés par un script (le chemin et l'utilisateur sont
remplacés automatiquement) :

```bash
sudo deploy/dev/install-web.sh
```

Ce que fait la configuration : seul `public/` est exposé, seul `index.php` est exécuté (tout autre `.php`
→ 404, scénario R1 §3.4), fichiers cachés (`.env`, `.git`) refusés, version de Nginx masquée,
pièces jointes limitées à 5 Mo. HTTPS, HSTS et CSP arrivent en S5/S8.

---

## C. Projet Symfony 7.4

### C.1 Création (faite une seule fois, pour mémoire)

```bash
composer create-project symfony/skeleton:"7.4.*" /tmp/sf
cp -a /tmp/sf/. ~/ticket-tournelle/     # sans écraser .gitignore ni README.md
composer config extra.symfony.docker false
composer require symfony/twig-bundle symfony/security-bundle symfony/orm-pack \
  doctrine/doctrine-migrations-bundle symfony/form symfony/validator symfony/translation \
  symfony/security-csrf symfony/monolog-bundle symfony/asset-mapper
composer require --dev symfony/maker-bundle symfony/test-pack symfony/web-profiler-bundle
php bin/console importmap:require bootstrap
```

Paquets justifiés dans D-05. Bootstrap 5.3.9 est épinglé dans `importmap.php` et servi localement
(aucun CDN).

### C.2 Installation sur un nouveau poste (après le clone)

```bash
composer install
php bin/console importmap:install
php bin/console cache:clear
```

### C.3 Configuration de départ

- `.env` : valeurs **factices** uniquement (`DATABASE_URL` avec `CHANGE_ME`), `APP_SECRET` vide.
- `.env.dev` : vidé — la recette Symfony y avait écrit un `APP_SECRET` réel, déplacé dans `.env.local`.
- `config/packages/translation.yaml` : `default_locale: fr`, `enabled_locales: [fr, en]` (C2).
- `config/packages/security.yaml` : `algorithm: argon2id` explicite (D-08 : « auto » donnait bcrypt).
- Page d'accueil `/fr` et `/en` ; `/` redirige selon la langue du navigateur ; autre langue → 404.

### C.4 Vérifications (à relancer après chaque modification)

```bash
php bin/phpunit                                    # 6 tests : accueil FR/EN, redirection, Argon2id
composer audit                                     # 0 vulnérabilité attendue (ENF-23)
gitleaks detect --source . --redact --no-banner    # 0 fuite attendue (ENF-11)
git check-ignore -v .env.local                     # doit afficher la règle du .gitignore
```

---

## D. Procédure en cas de secret exposé (ENF-13)

1. **Changer immédiatement le secret** (mot de passe SQL : `ALTER USER … IDENTIFIED BY …` ; `APP_SECRET` :
   en générer un nouveau), même si le commit est retiré : il reste dans l'historique et sur GitHub.
2. Mettre à jour `.env.local` sur chaque poste et serveur concerné.
3. Nettoyer l'historique (réécriture + `git push --force` concerté avec le binôme), puis relancer gitleaks.
4. Ajouter une entrée au journal de décisions et prévenir le MOA si des données de production sont concernées.
