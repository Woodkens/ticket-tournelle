# R2 — Fiche exigences non fonctionnelles

Ticket'Tournelle · Nov 14, 2026 · @Wood

Cette fiche fixe, avant le développement, les exigences de qualité et de sécurité que Ticket'Tournelle doit respecter ; chacune porte un identifiant (ENF-xx) et la façon dont on la vérifiera en recette (R4).

| Champ | Valeur |
| --- | --- |
| Binôme | William Matondo Ngongo · Woodkens Guervil |
| Rendu | R2 — fin de S4 |
| Références | Cahier des charges V1.2, R1 Volet sécurité |

## 1. Mots de passe et authentification

| ID | Exigence | Vérification |
| --- | --- | --- |
| ENF-01 | Hachage Argon2id avec sel unique (hasher Symfony `algorithm: argon2id`, D-08) ; aucun mot de passe stocké en clair ni chiffré de façon réversible | Lecture de la table UTILISATEUR : empreinte commençant par `$argon2id$` |
| ENF-02 | 12 caractères minimum pour les utilisateurs, 15 pour les administrateurs ; refus des mots de passe courants ou identiques à l'identifiant | Création d'un compte avec un mot de passe de 11 caractères → refusée |
| ENF-03 | 5 échecs en 15 minutes pour un même couple identifiant + adresse IP bloquent la connexion jusqu'à la fin de ces 15 minutes ; 25 échecs depuis une même adresse IP la bloquent pour tous les comptes ; message d'erreur générique. Le compte n'est pas verrouillé quelle que soit l'adresse IP : n'importe qui pourrait sinon bloquer un collègue (D-11) | 6 essais erronés → blocage, échecs présents dans le journal |
| ENF-04 | Mot de passe provisoire à changer à la première connexion ; lien de réinitialisation à usage unique qui expire | Lien réutilisé ou expiré → refusé |
| ENF-05 | 2FA TOTP obligatoire pour les administrateurs, 8 codes de secours à usage unique | Connexion admin sans code valide → refusée |

Pourquoi Argon2id plutôt que MD5 ou SHA1 : ces deux fonctions sont rapides et conçues pour vérifier l'intégrité d'un fichier ; une carte graphique en calcule des milliards par seconde. Argon2id est volontairement lent et gourmand en mémoire, ce qui rend la force brute très coûteuse.

## 2. Sessions

| ID | Exigence | Vérification |
| --- | --- | --- |
| ENF-06 | Cookie de session HttpOnly, Secure et SameSite=Lax | Outils du navigateur : les trois attributs sont présents |
| ENF-07 | Nouvel identifiant de session à chaque connexion (contre la fixation de session) | Identifiant relevé avant et après connexion : différent |
| ENF-08 | Déconnexion après 30 minutes d'inactivité (15 pour un administrateur) ; durée maximale 8 h ; pas de « se souvenir de moi » pour les admins | Session inactive au-delà du délai → retour à l'écran de connexion |
| ENF-09 | La déconnexion détruit la session côté serveur | Cookie réutilisé après déconnexion → accès refusé |

Pourquoi 30 minutes : les postes des salles sont partagés ; une session oubliée ouverte doit se fermer d'elle-même avant le cours suivant.

## 3. Secrets

Mots de passe de base de données, clé de l'application (APP\_SECRET), identifiants SMTP et clé de chiffrement des secrets TOTP.

| ID | Exigence | Vérification |
| --- | --- | --- |
| ENF-10 | Secrets uniquement dans `.env.local` sur le serveur, droits 640 (root:www-data) | `ls -l .env.local` sur le serveur |
| ENF-11 | `.env.local` dans le `.gitignore` dès le premier commit ; le `.env` committé ne contient que des valeurs factices | Scan des secrets (gitleaks) sur tout l'historique Git : 0 alerte |
| ENF-12 | Secrets de production différents de ceux de développement ; jamais transmis par Trello, e-mail ou l'espace collaboratif | Revue du Trello et de l'espace collaboratif avant S10 |
| ENF-13 | Un secret exposé est changé immédiatement, même retiré du dépôt (il reste dans l'historique) | Procédure écrite dans le README technique |

## 4. Journalisation

| ID | Exigence | Vérification |
| --- | --- | --- |
| ENF-14 | Journaliser : connexions réussies et échouées, blocages, réinitialisations de mot de passe et de 2FA, actions d'administration, changements de statut, refus d'accès (403), uploads refusés | Chaque action déclenchée en recette apparaît dans le journal |
| ENF-15 | Chaque entrée contient : acteur (s'il est connu), action, cible, adresse IP, horodatage | Lecture d'une entrée de JOURNAL\_AUDIT |
| ENF-16 | Stockage double : table JOURNAL\_AUDIT (l'application peut seulement ajouter, ni modifier ni supprimer) et fichier `/var/log/tickettournelle/security.log` (propriétaire root:adm) | Tentative d'UPDATE sur JOURNAL\_AUDIT avec le compte applicatif → refusée |
| ENF-17 | Jamais de mot de passe, de code TOTP ni de contenu de ticket dans les journaux ; conservation 6 mois avec rotation (logrotate) ; consultation réservée aux administrateurs | Recherche d'un mot de passe de test dans les journaux : absent |

## 5. Sauvegarde et restauration

| ID | Exigence | Vérification |
| --- | --- | --- |
| ENF-18 | Sauvegarde quotidienne automatique (cron) de la base et des pièces jointes | Présence d'une sauvegarde datée de la veille |
| ENF-19 | Sauvegarde chiffrée, copiée sur un support séparé du serveur, lisible par root seulement | Contrôle de la destination et des droits |
| ENF-20 | Rétention de 14 jours : les sauvegardes plus anciennes sont supprimées automatiquement | Nombre de sauvegardes conservées ≤ 14 |
| ENF-21 | Restauration réellement testée en S8 sur une base vide, avec rapport (durée, données retrouvées) | Rapport de test de restauration (jalon S8) |

Une sauvegarde jamais restaurée ne prouve rien : c'est pourquoi le tableau de suivi exige un test réel, « pas juste un cron qui tourne ». La rétention de 14 jours est un choix du binôme, à ajuster selon la place disponible.

## 6. Versions retenues et durée de support

Toutes les briques restent maintenues au moins jusqu'à mi-2028, bien au-delà de la livraison en S10 ; vérification faite le 14/11/2026.

| Brique | Version | Support de sécurité jusqu'à | Source |
| --- | --- | --- | --- |
| Debian | 13 « Trixie » | Juin 2030 (LTS comprise) | [Debian / endoflife](https://endoflife.ai/article-debian-12-eol-june-2026.html) |
| PHP | 8.4 (paquet Debian 13) | 31 décembre 2028 (support actif jusqu'au 31 décembre 2026) | [endoflife.ai](https://endoflife.ai/article-php-eol.html) |
| Symfony | 7.4 LTS | Novembre 2029 (bugs jusqu'en novembre 2028) | [symfony.com](https://symfony.com/blog/preparing-for-symfony-7-4-and-symfony-8-0) |
| MariaDB | 11.8 LTS (paquet Debian 13) | Juin 2028 | [CloudPanel](https://www.cloudpanel.io/blog/debian-13-and-mariadb-11.8-support/) |
| Nginx | Paquet Debian 13 | Suit les mises à jour de sécurité Debian | — |
| Dépendances Composer (scheb/2fa-bundle, Doctrine…) | Figées dans `composer.lock` | Vérifiées par `composer audit` | — |
| Bootstrap | 5.x | Figée dans le dépôt | — |

| ID | Exigence | Vérification |
| --- | --- | --- |
| ENF-22 | Uniquement des versions encore supportées, installées depuis les dépôts officiels Debian (pas de dépôt tiers) | `apt policy php8.4 mariadb-server nginx` |
| ENF-23 | Mises à jour de sécurité automatiques du système (unattended-upgrades) et `composer audit` à chaque mise à jour des dépendances : 0 vulnérabilité connue à la livraison | Sortie de `composer audit` jointe au R4 |

## 7. Autres exigences non fonctionnelles

Les valeurs cibles ci-dessous sont des choix du binôme, cohérents avec les hypothèses H2 (charge modérée) et H8 (conservation) du cahier des charges.

| ID | Domaine | Exigence | Vérification |
| --- | --- | --- | --- |
| ENF-24 | Disponibilité | Service accessible pendant les heures d'ouverture du lycée ; remise en service en moins d'une demi-journée à partir de la dernière sauvegarde | Durée mesurée lors du test de restauration (ENF-21) |
| ENF-25 | Performance | Pages affichées en moins de 2 s sur le réseau du lycée, avec 50 utilisateurs connectés en même temps | Test de charge simple en S9 |
| ENF-26 | Compatibilité | Fonctionne sur les versions récentes de Firefox, Chrome et Edge ; affichage adapté au smartphone pour déclarer depuis une salle | Parcours de déclaration testé sur ordinateur et smartphone |
| ENF-27 | Bilinguisme (C2) | 100 % des libellés, messages d'erreur et e-mails disponibles en français et en anglais ; aucun texte écrit en dur dans le code | Revue de toutes les vues en anglais en S9 |
| ENF-28 | Accessibilité minimale | Chaque champ de formulaire a un libellé, contraste suffisant, navigation possible au clavier | Contrôle visuel et navigation clavier en S9 |
| ENF-29 | Transport et en-têtes | HTTPS obligatoire (HTTP redirigé), HSTS, Content-Security-Policy, X-Frame-Options ; mode debug désactivé en production | Analyse des en-têtes de réponse en S8 |
| ENF-30 | Pièces jointes | JPG et PNG uniquement, 5 Mo maximum, type vérifié côté serveur (extension + MIME + magic number), fichier renommé et stocké hors de la racine web | Envoi d'un .php renommé en .jpg → refusé |
| ENF-31 | Données personnelles (RGPD) | Seules les données nécessaires sont collectées (nom, prénom, e-mail professionnel) ; aucun hébergement tiers ; durées de conservation selon H8 | Revue du MCD et de la configuration |
| ENF-32 | Maintenabilité | Code sur Git avec revue croisée avant fusion ; README technique à jour (installation, configuration, procédures) | README relu par l'autre membre du binôme |
