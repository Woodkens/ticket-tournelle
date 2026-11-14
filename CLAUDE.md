# Ticket'Tournelle — instructions pour Claude Code

## Contexte
- Projet d'Atelier de Professionnalisation, BTS SIO 2 SLAM, Lycée La Tournelle.
- Binôme : William Matondo Ngongo et Woodkens Guervil.
- Application : portail web de déclaration et de suivi d'incidents techniques pour 200+ personnels.
- Les étudiants doivent pouvoir expliquer et défendre chaque ligne à l'oral devant un jury.

## Documents de référence (à lire avant toute nouvelle fonctionnalité)
- `docs/cahier-des-charges-V1.2.md` : besoins, user stories, matrice des droits (§2.1), cycle de vie des 8 statuts (§3), catégories et priorités (§4), MCD/MLD (§6), stack (§7), planning (§8).
- `docs/R1-volet-securite.md` : actifs, surface d'attaque, scénarios de risque, mesures de sécurité.
- `docs/R2-exigences-non-fonctionnelles.md` : exigences ENF-01 à ENF-32 et leur vérification.
- `docs/ticket_tournelle_mpd.sql` : schéma physique de référence (tables, contraintes, index, droits SQL).
- Ne pas s'écarter de ces documents sans le signaler. Tout écart = une entrée dans le journal de décisions + la mise à jour du document concerné.

## Stack (figée)
- Debian 13, Nginx + PHP-FPM 8.4, MariaDB 11.8.
- Symfony 7.4 LTS, Doctrine ORM et migrations, Twig + Bootstrap 5.
- Symfony Security (hasher `algorithm: argon2id` explicite, D-08), scheb/2fa-bundle (TOTP), Symfony Translation (fr, en).
- Aucune autre dépendance sans l'accord du binôme ; versions figées dans `composer.lock`, vérifiées avec `composer audit`.

## Façon de travailler
- Toujours répondre en français.
- Travailler une séance à la fois (cartes Trello S4 à S9). Commencer par un plan et attendre la validation avant de modifier des fichiers.
- Expliquer chaque choix technique en 2 ou 3 phrases simples, compréhensibles par un étudiant de BTS.
- Petits commits, messages en français au format `type(portée): description` (types : feat, fix, sec, test, docs, chore). Branches : `feature/<sujet>`.
- Après chaque fonctionnalité : lancer les tests, puis indiquer comment vérifier à la main dans le navigateur.

## Règles de sécurité (non négociables)
- Aucun secret dans Git : secrets uniquement dans `.env.local` (gitignoré) ; `.env` ne contient que des valeurs factices. Ne jamais lire ni afficher `.env.local`.
- Mots de passe : hasher Symfony `algorithm: argon2id` (le mode « auto » choisit bcrypt, voir D-08). Jamais MD5 ni SHA1.
- Chaque route vérifie les droits côté serveur (Voters), selon la matrice du CdC §2.1. Un utilisateur ne voit que ses propres tickets (protection IDOR).
- Accès à la base uniquement via Doctrine ou des requêtes préparées ; jamais de concaténation dans une requête SQL.
- Échappement Twig jamais désactivé sur des données saisies par un utilisateur (pas de `|raw`).
- Jeton CSRF sur tous les formulaires.
- Aucune suppression physique : tickets archivés, comptes désactivés.
- Compte SQL applicatif `tt_app` sans DELETE, DROP, ALTER ni GRANT ; `historique_statut` et `journal_audit` en ajout seul. Les migrations utilisent `tt_migrate`.
- Pièces jointes : JPG/PNG uniquement, 5 Mo max, vérification extension + MIME + magic number, nom aléatoire, stockage hors de `public/`.
- Comptes admin : nominatifs, distincts du compte utilisateur, TOTP obligatoire, toutes les actions journalisées.
- Production : `APP_ENV=prod`, `APP_DEBUG=0`, en-têtes HSTS, CSP et X-Frame-Options.

## Domaine (rappels)
- Rôles : ROLE_USER, ROLE_TECHNICIEN, ROLE_ADMIN, ROLE_DIRECTION (lecture seule des indicateurs).
- Statuts : nouveau, assigne, en_cours, en_attente, resolu, cloture, rejete, annule (transitions : CdC §3).
- Interface et e-mails en français et en anglais : aucun texte écrit en dur, tout passe par `translations/`.

## Journal de décisions (rendu R3)
- Après chaque choix technique ou cyber notable (bibliothèque, configuration de sécurité, modèle de données, problème contourné), ajouter une entrée dans `docs/journal-decisions.md` avec la skill `/decision`.

## Commandes du projet
- Le projet vit dans `~/ticket-tournelle` sous WSL2 / Debian 13 (pas sous Windows). Installation complète : `README.md`.
- Tests : `php bin/phpunit`
- Audit des dépendances : `composer audit`
- Scan des secrets : `gitleaks detect --source . --redact` (lancé aussi par le hook `.githooks/pre-push`)
- Cache : `php bin/console cache:clear`
- Migrations (compte `tt_migrate`, hors projet) : `(set -a; . ~/.config/tickettournelle/migrate.env; php bin/console doctrine:migrations:migrate)`
- Site de dev : `http://localhost/` (Nginx + PHP-FPM, config dans `deploy/dev/`)
