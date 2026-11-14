# R1 — Volet sécurité du cahier des charges

Ticket'Tournelle&#32;

Ce document est le référentiel cyber de Ticket'Tournelle : il fixe ce qu'on protège, contre quoi, et avec quelles mesures, avant tout développement.

| Champ | Valeur |
| --- | --- |
| Binôme | Woodkens GUERVIL · William Matondo |
| Date de remise | Fin de séance 3 |
| Validation MOA | ☐ Validé ☐ À corriger |

## Section 1 — Ce qu'il faut protéger

Huit actifs sont retenus, répartis sur les cinq catégories. DICP : Disponibilité, Intégrité, Confidentialité, Preuve (traçabilité).

| Actif | Catégorie | Pourquoi il a de la valeur pour le lycée | DICP prioritaire |
| --- | --- | --- | --- |
| Base des tickets (signalements, déclarants, commentaires, pièces jointes) | Donnée | Mémoire du service. Elle révèle les faiblesses techniques du lycée (salles, matériels, réseau) et contient des données personnelles des 200 personnels (RGPD). | Confidentialité |
| Portail web Ticket'Tournelle | Service | Il devient le seul canal de signalement une fois l'Excel et les SMS abandonnés. S'il tombe, plus aucune déclaration ni aucun suivi. | Disponibilité |
| Comptes nominatifs des personnels (identifiants + empreintes de mots de passe) | Identité | Ils garantissent qui a déclaré quoi. Usurpés, ils permettent de créer de faux tickets ou de lire ceux d'un collègue. | Intégrité |
| Comptes administrateurs du site | Identité | Ils donnent tous les droits : comptes, rôles, configuration. Leur compromission équivaut à celle de toute l'application. | Intégrité |
| Journal d'audit (connexions, actions admin, changements de statut) | Donnée | Il prouve qui a fait quoi et quand. C'est la base de toute enquête après un incident ou un litige. | Preuve |
| Serveur interne Debian (Nginx, PHP, MariaDB) administré par le binôme | Composant | Il héberge l'application et la base. Sa panne ou sa compromission entraîne celle de tous les autres actifs. | Disponibilité |
| Code source et dépôt Git | Composant | Il contient la logique de contrôle d'accès. Modifié, il peut cacher une porte dérobée ; mal géré, il peut exposer des secrets (.env). | Intégrité |
| Confiance des personnels et image de l'établissement | Immatériel | L'adoption de l'outil dépend de la confiance des utilisateurs. Une fuite ferait revenir aux SMS et nuirait à l'image du lycée. | Confidentialité |

## Section 2 — Analyse de la surface d'attaque

Douze points d'entrée sont recensés ; l'objectif est qu'un seul port (443) reste joignable par les utilisateurs.

| Élément exposé | Pourquoi il augmente la surface | Mesure de réduction envisagée |
| --- | --- | --- |
| Page de connexion | Cible des essais de mots de passe en masse (force brute, mots de passe issus de fuites). | Blocage temporaire après 5 échecs en 15 min, message d'erreur générique, HTTPS obligatoire, journalisation des échecs. |
| Formulaires (ticket, commentaire, recherche) | Chaque champ est une entrée utilisateur : injection SQL, XSS stockée, CSRF. | Requêtes préparées (ORM Doctrine), échappement automatique Twig, validation côté serveur, jeton CSRF sur chaque formulaire. |
| Upload de pièces jointes | Un fichier peut être un script exécutable (webshell .php) ou saturer le disque. | Liste blanche jpg/png, contrôle MIME + extension + magic number côté serveur, 5 Mo max, renommage aléatoire, stockage hors du dossier web. |
| URL avec identifiant (/ticket/{id}) | Changer l'id dans l'URL peut donner accès au ticket d'un autre (IDOR). | Vérification serveur à chaque requête : propriétaire, référent affecté ou admin, sinon 403 journalisé. |
| Interface d'administration (/admin) | Elle concentre les fonctions les plus puissantes. | Rôle ADMIN vérifié côté serveur, comptes admin nominatifs, double authentification (TOTP), accès limité aux postes des référents. |
| Port SSH (22) du serveur | Une connexion SSH réussie donne la main sur tout le serveur. | Authentification par clé uniquement, root interdit, accès depuis le réseau d'administration seulement, fail2ban. |
| Port MariaDB (3306) | Un accès direct à la base contourne toutes les règles de l'application. | Écoute sur 127.0.0.1 uniquement, port fermé au pare-feu, compte applicatif à droits minimaux. |
| Dépendances tierces (framework, bibliothèques Composer) | Une bibliothèque vulnérable (CVE connue) rend l'application vulnérable. | Versions figées (composer.lock), `composer audit` à chaque mise à jour, seuls les paquets nécessaires. |
| Dépôt Git | Un mot de passe de base committé par erreur reste dans l'historique. | .gitignore dès le 1er commit, secrets dans .env.local hors Git, dépôt privé, scan des secrets avant chaque push. |
| Sauvegardes de la base | Une sauvegarde est une copie complète des données : volée, c'est une fuite totale. | Stockage sur un support séparé, lisible par root seulement, chiffrement, rétention limitée. |
| Pages d'erreur et mode debug | Le debug affiche chemins, versions et variables : une carte pour l'attaquant. | APP\_ENV=prod et debug désactivé, pages d'erreur génériques, versions Nginx/PHP masquées (server\_tokens off, expose\_php Off). |
| Notifications e-mail | Le contenu d'un ticket part hors de l'application ; un faux e-mail peut imiter le portail. | E-mail minimal (numéro, statut, lien) sans détail sensible, envoi par le relais SMTP de l'établissement en TLS. |

## Section 3 — Scénarios de risque

Quatre scénarios, chacun construit sur la chaîne complète.&#32;

### 3.1 — Compromission d'un compte enseignant

- **Actif** : le compte nominatif d'un enseignant et les tickets qu'il a déclarés.
- **Vulnérabilité** : mot de passe faible ou réutilisé sur un autre site, sans limitation du nombre d'essais.
- **Menace** : une personne extérieure qui récupère ce mot de passe dans une fuite d'un autre site, ou un élève qui l'a vu saisi sur le poste de la salle (vidéoprojecteur allumé, session laissée ouverte).
- **Événement redouté** : connexion frauduleuse au nom de l'enseignant ; lecture de ses tickets, création de faux signalements ou de commentaires en son nom.
- **Conséquences** : référents mobilisés pour rien, traçabilité faussée (on ne sait plus qui a vraiment déclaré), usurpation d'identité d'un personnel, atteinte à des données personnelles (RGPD).
- **Mesures** : longueur minimale de 12 caractères et refus des mots de passe courants, blocage après 5 échecs, déconnexion après 30 min d'inactivité, journalisation des connexions.

### 3.2 — Accès non autorisé aux tickets d'un autre utilisateur (IDOR)

- **Actif** : la base des tickets, en particulier les tickets et pièces jointes des autres personnels.
- **Vulnérabilité** : l'application vérifie seulement que l'utilisateur est connecté, pas qu'il a le droit de voir le ticket n° X ; les numéros sont séquentiels et faciles à deviner.
- **Menace** : un personnel authentifié et curieux qui remplace /ticket/42 par /ticket/43 dans la barre d'adresse.
- **Événement redouté** : lecture, modification ou clôture des tickets d'un collègue, avec leurs pièces jointes.
- **Conséquences** : fuite de données entre collègues (RGPD), tickets clôturés à tort et non traités, conflits internes, perte de confiance dans l'outil.
- **Mesures** : contrôle d'accès côté serveur sur chaque route (propriétaire, référent affecté ou admin), refus en 403 journalisé, test croisé A/B documenté en S7.

### 3.3 — Compromission du compte administrateur du site

- **Actif** : le compte administrateur, et à travers lui tous les comptes, rôles et le journal d'audit.
- **Vulnérabilité** : un compte « admin » générique partagé entre plusieurs référents, sans double authentification, jamais changé après le départ d'un membre.
- **Menace** : un ancien référent ou stagiaire qui connaît encore le mot de passe, ou un attaquant qui l'obtient par un faux e-mail (hameçonnage) envoyé à un référent.
- **Événement redouté** : prise de contrôle de l'application ; création de comptes, élévation de privilèges, suppression de tickets, effacement de traces.
- **Conséquences** : intégrité de toutes les données perdue, impossible d'identifier l'auteur puisque le compte est partagé, service arrêté le temps de la remise en état, fuite possible de toute la base.
- **Mesures** : comptes admin nominatifs et distincts du compte utilisateur de la même personne, TOTP obligatoire, revue des comptes à chaque départ, journal des actions admin non modifiable par l'application.

### 3.4 — Upload d'un fichier malveillant

- **Actif** : le serveur interne et toutes les données qu'il héberge.
- **Vulnérabilité** : l'extension n'est vérifiée que dans le navigateur, et les fichiers sont rangés dans un dossier où le serveur web exécute le PHP.
- **Menace** : un utilisateur malveillant, ou un compte compromis (3.1), qui joint un script nommé photo.php ou photo.jpg.php.
- **Événement redouté** : exécution de code sur le serveur ; lecture du fichier de configuration, copie de la base, rebond vers le réseau interne du lycée.
- **Conséquences** : fuite complète des tickets et comptes, menace sur le reste du réseau de l'établissement, service indisponible, responsabilité directe du binôme administrateur du serveur (C3).
- **Mesures** : contrôle MIME + extension + magic number côté serveur, renommage aléatoire, stockage hors du dossier web, téléchargement via un contrôleur, test d'envoi d'un .php en S8.

## Section 4 — Mesures par principe fondamental

Chaque principe est traduit en une action précise sur un élément précis de Ticket'Tournelle.

| Principe | Mesure concrète prévue dans Ticket'Tournelle |
| --- | --- |
| 1. Moindre privilège | Le compte MariaDB `tt_app` n'a que SELECT, INSERT et UPDATE sur la base `tickettournelle` (et seulement SELECT et INSERT sur la table journal_audit) : ni DROP, ni GRANT, ni FILE. Dans l'application, un utilisateur ne voit que ses propres tickets, un technicien voit tous les tickets, la Direction ne voit que les indicateurs, et seul l'admin gère les comptes. |
| 2. Défense en profondeur | L'accès à /admin passe par quatre barrières successives : mot de passe d'au moins 15 caractères, code TOTP, poste autorisé, journalisation de chaque action. Si une barrière cède, les autres tiennent. |
| 3. Réduction de surface | Le pare-feu (ufw) n'ouvre que le port 443 aux utilisateurs, plus le 80 redirigé vers 443. SSH n'est ouvert qu'au réseau d'administration, MariaDB écoute en local, et il n'y a pas de phpMyAdmin en production. |
| 4. Cloisonnement | Les pièces jointes sont stockées hors du dossier web (`/var/lib/tickettournelle/uploads`). Les logs appartiennent à un autre compte que l'application (root:adm). L'espace /admin a son propre rôle et sa propre règle d'accès dans la configuration de sécurité Symfony (access\_control). |
| 5. Security by Design | Le contrôle d'accès par ticket (Voter Symfony « voir / modifier / clôturer ») est conçu dans le MCD et les user stories, avant la première ligne de code. Les menaces sont analysées dans ce R1, avant le développement. |
| 6. Security by Default | Tout nouveau compte reçoit le rôle le plus faible (utilisateur) et un mot de passe à changer dès la première connexion. Les cookies sont Secure, HttpOnly et SameSite, le debug est désactivé et HTTPS est forcé (HSTS) sans action de l'administrateur. |

## Section 5 — Réponse aux contraintes cyber du MOA

Les neuf contraintes sont couvertes ; C4 et C5, les deux contraintes purement cyber, ont chacune plusieurs mesures vérifiables.

| Contrainte | Résumé | Comment notre solution y répond |
| --- | --- | --- |
| C1 | Compte nominatif par personnel | Un compte par personne, identifié par son adresse e-mail professionnelle, créé par import CSV de la liste fournie par la direction. Aucun compte partagé : chaque action est imputable à une personne. Le compte est désactivé au départ du personnel. |
| C2 | Interface bilingue français/anglais | Fichiers de traduction Symfony (fr, en) pour l'interface et les e-mails, langue choisie dans le profil. Les messages de sécurité restent génériques dans les deux langues (pas de « utilisateur inconnu »). |
| C3 | Hébergement interne administré par les étudiants | Serveur Debian installé par le binôme sur une machine du lycée : pare-feu, SSH par clé, mises à jour de sécurité automatiques (unattended-upgrades), HTTPS, sauvegardes testées. Le binôme assume la responsabilité d'administrateur. |
| C4 | Sécurité selon les bonnes pratiques + preuves | Référentiel : OWASP Top 10 et recommandations de l'ANSSI. Mesures : Argon2id, limitation des essais, requêtes préparées, échappement, CSRF, contrôle d'accès, en-têtes HTTP. Preuve : chaque mesure a un test et une capture dans le rapport de recette R4. |
| C5 | Comptes administrateurs selon les recommandations en vigueur | Suivant le guide ANSSI sur l'administration sécurisée : comptes admin nominatifs, distincts du compte utilisateur de la même personne, double authentification TOTP, mot de passe d'au moins 15 caractères, accès depuis les postes des référents, toutes les actions journalisées, revue des comptes à chaque départ. |
| C6 | Pilotage via Trello | Tableau privé partagé avec le binôme et le MOA uniquement. Aucun mot de passe ni secret dans les cartes. |
| C7 | Espace collaboratif documentaire | Espace réservé au binôme et au MOA, documents versionnés. Aucun secret stocké : les mots de passe restent sur le serveur. |
| C8 | Documentation d'utilisation | La doc inclut les bons gestes : choisir un mot de passe, se déconnecter en salle, ne pas mettre d'informations sensibles dans un ticket, signaler un accès suspect. La version admin décrit les procédures d'arrivée et de départ. |
| C9 | Cadre Atelier de professionnalisation | Journal de décisions cyber (R3), questions au MOA écrites et datées, validation formelle de chaque rendu avant de passer à la phase suivante. |

## Section 6 — Choix techniques structurants

Stack retenue (cahier des charges V1.2) : Debian 13 + Nginx + PHP 8.4 + Symfony 7.4 LTS + MariaDB 11.8 LTS, toutes maintenues au-delà de la fin du projet. Symfony est le framework enseigné en SIO SLAM.

### 6.1 — Stack technique retenue

| Couche | Choix et version | Support |
| --- | --- | --- |
| Système | Debian 13 « Trixie » | Distribution stable, mises à jour de sécurité via apt jusqu'en 2030 ([source](https://www.cloudpanel.io/blog/debian-13-and-mariadb-11.8-support/)) |
| Serveur web | Nginx + PHP-FPM (paquets Debian) | Suit les mises à jour de sécurité Debian |
| Langage | PHP 8.4 (version par défaut de Debian 13) | Paquet officiel Debian ([source](https://linuxcapable.com/how-to-install-php-on-debian-linux/)) |
| Framework | Symfony 7.4 LTS | Correctifs de bugs jusqu'en novembre 2028, de sécurité jusqu'en novembre 2029 ([source](https://symfony.com/blog/preparing-for-symfony-7-4-and-symfony-8-0)) |
| SGBD | MariaDB 11.8 LTS (version de Debian 13) | Support jusqu'en juin 2028 ([source](https://www.cloudpanel.io/blog/debian-13-and-mariadb-11.8-support/)) |

Justification :

- **Maintenance** : tout vient des dépôts officiels Debian, donc un simple `apt upgrade` applique les correctifs, sans dépôt tiers à surveiller.
- **Sécurité** : Symfony fournit en standard le hachage Argon2id, la protection CSRF, le contrôle d'accès par Voter, la limitation des connexions et l'échappement Twig. On n'invente rien soi-même.
- **Compatibilité** : Symfony 7.4 demande PHP 8.2 minimum, PHP 8.4 convient. Le composant Translation répond directement à C2.
- **Durée de vie** : une LTS reste supportée après notre départ, ce qui compte pour le lycée qui gardera l'outil.

### 6.2 — Politique de mots de passe et de hachage

- **Algorithme** : Argon2id (hasher « auto » de Symfony), avec un sel unique par mot de passe généré automatiquement.
- **Longueur** : 12 caractères minimum pour les utilisateurs, 15 pour les administrateurs (en plus du TOTP). La longueur protège mieux que des règles de composition compliquées.
- **Refus** : mots de passe trop courants ou déjà présents dans des fuites connues, et mot de passe identique à l'identifiant.
- **Blocage** : 5 échecs en 15 minutes bloquent temporairement le compte et l'adresse IP (login throttling Symfony), avec un message d'erreur générique.
- **Premier accès** : mot de passe provisoire à changer obligatoirement à la première connexion.

Pourquoi MD5 et SHA1 seuls ne conviennent pas : ce sont des fonctions rapides, conçues pour vérifier l'intégrité d'un fichier, pas pour stocker un mot de passe. Une carte graphique en calcule des milliards par seconde, et sans sel les tables arc-en-ciel retrouvent instantanément les mots de passe courants. Des collisions sont de plus connues pour les deux. Argon2id est volontairement lent et gourmand en mémoire, ce qui rend l'attaque par force brute très coûteuse.

### 6.3 — Gestion des sessions

- **Cookie de session** : HttpOnly (illisible par JavaScript, contre le vol par XSS), Secure (envoyé seulement en HTTPS), SameSite=Lax (contre le CSRF).
- **Fixation de session** : nouvel identifiant de session généré à chaque connexion.
- **Inactivité** : déconnexion automatique après 30 minutes, 15 minutes pour un administrateur. C'est important sur les postes partagés des salles.
- **Durée maximale** : 8 heures, soit une journée de cours. Pas de « se souvenir de moi » pour les comptes admin.
- **Déconnexion** : la session est détruite côté serveur, pas seulement le cookie effacé.

### 6.4 — Gestion des secrets

- **Stockage** : mots de passe de base, clé secrète de l'application (APP\_SECRET) et identifiants SMTP sont dans `.env.local` sur le serveur, avec les droits 640 (root:www-data).
- **Hors Git** : le `.env` committé ne contient que des valeurs factices ; `.env.local` est dans le `.gitignore` dès le premier commit.
- **Contrôle** : scan des secrets (gitleaks) avant chaque push, et vérification de l'historique avant le rendu du dépôt.
- **Séparation** : secrets de production différents de ceux de développement, et jamais transmis par Trello, e-mail ou l'espace collaboratif.
- **Incident** : un secret exposé est changé immédiatement, même s'il est retiré du dépôt ensuite (il reste dans l'historique).

### 6.5 — Journalisation

| Quoi | Où | Qui peut modifier |
| --- | --- | --- |
| Connexions réussies et échouées, blocages (identifiant, IP, horodatage) | Fichier `/var/log/tickettournelle/security.log` (Monolog) | Personne via l'application ; lecture par root et le groupe adm |
| Actions admin : création, désactivation, changement de rôle, réinitialisation | Table `journal_audit` en base + fichier | L'application peut ajouter (INSERT) mais ni modifier ni supprimer |
| Changements de statut et d'affectation des tickets | Table `historique_statut` en base | Idem : ajout seul |
| Refus d'accès (403, tentatives IDOR), uploads refusés | Fichier `security.log` | Personne via l'application |

Règles communes : jamais de mot de passe ni de contenu de ticket dans les logs, rotation par logrotate, conservation 6 mois, consultation réservée aux administrateurs.

## Section 7 — Engagement du binôme

Le binôme reconnaît que ce document constitue le référentiel cyber du projet Ticket'Tournelle. Toute modification ultérieure des choix techniques listés en section 6 fera l'objet d'une demande écrite au MOA. Le binôme s'engage à produire les rendus R2 à R5 aux échéances indiquées dans le brief de S1.

| Membre 1 — Signature | Membre 2 — Signature |
| --- | --- |
| Woodkens GUERVIL | William MATONDO |

Validation MOA — signature et date : \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

## Annexe — Fiche de révision pour l'oral (à ne pas imprimer)

Le jury demande de justifier, pas de réciter. Voici les questions les plus probables et l'idée clé de chaque réponse, à reformuler avec vos mots.

| Question probable | Idée clé de la réponse |
| --- | --- |
| Pourquoi Argon2id et pas bcrypt ? | Les deux sont acceptables. Argon2id est en plus coûteux en mémoire, ce qui gêne les attaques par carte graphique. bcrypt tronque aussi les mots de passe au-delà de 72 octets. Argon2id a gagné la Password Hashing Competition et Symfony l'utilise par défaut. |
| Comment testez-vous l'IDOR ? | Deux comptes A et B. A crée un ticket et note son numéro. B, connecté, saisit l'URL de ce ticket : il doit recevoir un 403, et le refus doit apparaître dans security.log. Capture d'écran dans le R4. |
| Que faire si un log est corrompu ? | Le log est une preuve : on le compare au second support (table en base et fichier), on part de la dernière sauvegarde saine, et on traite l'altération elle-même comme un incident. C'est pourquoi l'application ne peut pas modifier ses propres logs. |
| Pourquoi un compte admin séparé du compte utilisateur ? | Un compte qui sert tous les jours (e-mails, navigation) est plus exposé. Séparé, le compte admin ne sert qu'à administrer : moins d'exposition et des traces claires. |
| Pourquoi un compte SQL dédié et pas root ? | Moindre privilège : si une injection SQL passait malgré les requêtes préparées, l'attaquant ne pourrait ni supprimer la base, ni lire des fichiers du serveur, ni créer de comptes. |
| HTTPS avec un certificat auto-signé, est-ce sécurisé ? | Le chiffrement est le même ; ce qui manque, c'est la confiance du navigateur. En interne, l'idéal est un certificat signé par une autorité interne déployée sur les postes du lycée. |
| Pourquoi un message d'erreur générique au login ? | « Identifiant inconnu » confirmerait à un attaquant qu'un autre compte existe. Un message unique ne lui apprend rien. |
