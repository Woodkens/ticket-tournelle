# Cahier des charges — Ticket'Tournelle V1.2

Nov 13, 2026 · @Wood

Lycée des métiers La Tournelle — BTS SIO 2 SLAM (2026-2027) — Portail de déclaration et de suivi d'incidents techniques.

| Champ | Valeur |
| --- | --- |
| MOA | M. Lorant-Raze et M. Chamayou |
| Binôme | William Matondo Ngongo et Woodkens Guervil |
| Évaluateurs | M. Gnadjro et M. Plumasseau |
| Version | V1.2 — 13/11/2026 (validation MOA requise) |

**Évolutions V1.1 → V1.2**

| Section | Modification | Pourquoi |
| --- | --- | --- |
| 1.1 | User stories réécrites au format « En tant que… je veux… afin de… », 8 → 21, priorisées MoSCoW | Format demandé dans le tableau de suivi (S2) ; couverture de toutes les fonctionnalités |
| 2 | Ajout d'une matrice des droits par rôle | Base directe des contrôles d'accès et des tests IDOR |
| 3 | Cycle de vie dessiné | Lecture plus rapide des 8 statuts |
| 4 | Catégorie « Demande d'équipement » ajoutée ; priorité calculée par une matrice impact × urgence | Le MOA cite les « manques d'équipements » ; la V1.1 ne disait pas comment la priorité était fixée |
| 6 | MCD redessiné sans clés étrangères, MLD séparé | En Merise, les clés étrangères n'apparaissent qu'au MLD |
| 7 | Debian 13, PHP 8.4, MariaDB 11.8, Symfony 7.4 LTS ; compte admin distinct du compte utilisateur | Debian 12 n'a plus que le support LTS depuis juillet 2026 ; recommandation ANSSI pour C5 |
| 8 | Planning réaligné sur le tableau de suivi du prof | CdC et R1 fin S3, R2 en S4, R3 et R4 en S9, sécurité dès S5 |
| 11 | C4 et C5 traités séparément, avec la preuve attendue | Le MOA demande la preuve des mesures (C4) |

Les sections 5 (maquettes) et 9 (risques) reprennent la V1.1, avec de petits ajustements signalés. La section 10 transforme les questions au MOA, posées en séance, en hypothèses retenues.

## 1. Contexte et reformulation du besoin

Le Lycée La Tournelle (La Garenne-Colombes) regroupe plus de 200 personnels. Les incidents techniques (matériel, logiciel, réseau, bâtiment) sont aujourd'hui signalés via un fichier Excel partagé et des canaux informels (SMS, WhatsApp, appels), ce qui entraîne perte de suivi, retards et absence de statistiques.

**Besoin central** : mettre à disposition un portail web unique et sécurisé, accessible via un compte nominatif, permettant aux 200+ agents de déclarer un incident, de suivre son traitement en temps réel, et offrant des indicateurs de pilotage à la direction.

### 1.1 User stories

21 user stories, priorisées en MoSCoW : M = indispensable, S = important, C = si le temps le permet. US01 à US08 reprennent la V1.1 au format attendu.

| ID | En tant que… | je veux… | afin de… | Critère d'acceptation | Prio |
| --- | --- | --- | --- | --- | --- |
| US01 | utilisateur | déclarer un incident en quelques champs | être pris en charge sans passer par SMS ou Excel | Moins de 5 champs obligatoires, n° de ticket généré | M |
| US02 | utilisateur | suivre l'état de mes demandes | ne plus avoir à relancer les techniciens | Vue « Mes tickets » à jour en temps réel | M |
| US03 | utilisateur | consulter l'interface en anglais | utiliser l'outil dans ma langue (C2) | Sélecteur FR/EN, langue mémorisée dans le profil | M |
| US04 | technicien | voir les tickets non assignés triés par priorité | traiter d'abord l'urgent, pas ce qui crie le plus fort | Tableau de bord filtrable et triable | M |
| US05 | technicien | changer le statut et commenter l'avancement | que le demandeur soit informé sans appel | Notification automatique au demandeur | M |
| US06 | administrateur | créer et désactiver des comptes | gérer les arrivées et départs (C1) | E-mail d'activation ; compte désactivé bloqué | M |
| US07 | direction | consulter des indicateurs de pilotage en lecture seule | dimensionner les moyens et anticiper les investissements | Tableau de bord avec au moins 3 indicateurs clés | S |
| US08 | technicien ou administrateur | être alerté immédiatement d'un ticket « Critique » | intervenir sans délai | E-mail + notification dans l'application | S |
| US09 | utilisateur | me connecter avec mon compte nominatif | que chaque déclaration me soit attribuée | Blocage temporaire après 5 échecs, message d'erreur générique | M |
| US10 | utilisateur | réinitialiser un mot de passe oublié | retrouver l'accès sans déranger un administrateur | Lien à usage unique qui expire | M |
| US11 | utilisateur | joindre une photo à mon ticket | montrer le problème sans long texte | JPG/PNG uniquement, taille limitée, autres types refusés | S |
| US12 | utilisateur | commenter mon ticket | apporter une précision ou répondre à une question | Commentaire public visible du technicien | M |
| US13 | utilisateur | confirmer la résolution ou rouvrir mon ticket | que le ticket ne soit clôturé que si le problème est réglé | Clôture automatique après 5 jours ouvrés sans réponse | S |
| US14 | utilisateur | annuler un ticket encore « Nouveau » | retirer une déclaration faite par erreur | Annulation impossible après affectation | C |
| US15 | technicien | m'affecter un ticket | que chacun sache qui s'en occupe | Un seul technicien responsable à la fois | M |
| US16 | technicien | rejeter un ticket avec un motif | traiter les doublons et les demandes hors périmètre | Motif obligatoire, visible du déclarant | S |
| US17 | technicien | écrire un commentaire interne | échanger entre techniciens sans exposer le déclarant | Commentaire interne invisible pour l'utilisateur | C |
| US18 | administrateur | gérer les salles, catégories et équipements | adapter l'outil sans modifier le code | Ajout, modification, désactivation sans suppression | S |
| US19 | administrateur | consulter le journal d'audit | savoir qui a fait quoi en cas d'incident | Actions sensibles listées, non modifiables par l'application | M |
| US20 | administrateur | saisir un code TOTP après mon mot de passe | qu'un mot de passe volé ne suffise pas | Accès admin refusé sans code valide | M |
| US21 | direction | exporter les indicateurs en CSV ou PDF | les présenter aux instances du lycée | Export de la période choisie | C |

## 2. Fonctionnalités proposées par rôle

Une séparation claire des privilèges (RBAC) est établie conformément aux exigences du MOA :

- **Utilisateur** (tout personnel) : connexion nominative, création de tickets (titre, salle, catégorie, photo), suivi avec historique, commentaires, confirmation ou réouverture, annulation tant que le ticket est « Nouveau », changement de langue (FR/EN).
- **Technicien** (référent numérique) : vue globale filtrable, auto-affectation, changement de statut avec justification obligatoire, commentaires internes ou publics, rejet motivé.
- **Administrateur** : gestion des comptes (sans suppression physique), paramétrage des référentiels (salles, catégories, équipements), affectation manuelle, journal d'audit et règles de clôture. Connexion avec 2FA TOTP obligatoire.
- **Direction** (lecture seule) — rôle proposé, à valider par le MOA (H1) : tableaux de bord synthétiques et exports CSV/PDF, sans droit d'administration.

### 2.1 Matrice des droits

Chaque rôle n'a que les droits dont il a besoin (moindre privilège). Chaque ligne est vérifiée côté serveur et testée en recette.

| Fonctionnalité | Utilisateur | Technicien | Administrateur | Direction |
| --- | --- | --- | --- | --- |
| Créer un ticket | Oui | Oui | Non (compte admin réservé à l'administration) | Oui |
| Voir un ticket et son historique | Les siens | Tous | Tous | Non (indicateurs seulement) |
| Commenter (public) | Les siens | Tous | Tous | Non |
| Commenter (interne) | Non | Oui | Oui | Non |
| Annuler un ticket « Nouveau » | Les siens | Non | Non | Non |
| Confirmer ou rouvrir | Les siens | Non | Non | Non |
| S'affecter, changer statut ou priorité | Non | Oui | Oui | Non |
| Rejeter avec motif | Non | Oui | Oui | Non |
| Tableau de bord et exports | Non | Lecture | Lecture | Lecture + export |
| Gérer les comptes et les rôles | Non | Non | Oui | Non |
| Gérer les référentiels | Non | Non | Oui | Non |
| Consulter le journal d'audit | Non | Non | Oui | Non |
| Supprimer un ticket ou un compte | Non | Non | Non : archivage ou désactivation uniquement | Non |

Une personne qui administre le site déclare ses propres incidents avec son compte utilisateur : le compte administrateur ne sert qu'à administrer (C5, voir section 7).

## 3. Cycle de vie d'un ticket

Le traitement suit 8 statuts qui garantissent une traçabilité intégrale ; « Rouvert » n'est pas un statut mais un retour de « Résolu » vers « En cours ».

&#91;embedded content: cycle de vie d'un ticket · 8 statuts\]

### 3.1 Règles de transition et notifications

| Statut | Déclenché par | Règle de gestion | Notification envoyée |
| --- | --- | --- | --- |
| Nouveau | Déclarant | Ticket visible dans la file générale non assignée | Accusé de réception (e-mail) ; alerte immédiate aux techniciens si « Critique » |
| Assigné | Technicien | Un seul technicien responsable à la fois | Déclarant + technicien |
| En cours | Technicien | Prise en charge active de l'incident sur le terrain | Déclarant informé |
| En attente | Technicien | Gel du compteur de délai de résolution | Demande d'information au déclarant |
| Résolu | Technicien | Solution saisie obligatoire ; déclenche un délai de validation de 5 jours ouvrés | Invitation à confirmer ou rouvrir |
| Clôturé | Demandeur ou système | Archivage en lecture seule pour la base statistique | Confirmation finale |
| Rejeté | Technicien ou admin | Depuis « Nouveau » ou « Assigné », motif obligatoire (doublon, hors périmètre) | Notification avec motif |
| Annulé | Déclarant | Possible uniquement tant que le statut est « Nouveau » | Information aux techniciens |

Chaque transition est enregistrée dans HISTORIQUE\_STATUT (ancien et nouveau statut, auteur, date, motif). C'est la base des indicateurs : le délai de résolution va de la création au passage à « Résolu », hors temps passé « En attente ». Un ticket « Clôturé » ne se rouvre pas : on crée un nouveau ticket.

## 4. Catégories et priorités

Six catégories orientent chaque ticket vers le bon intervenant, et la priorité se déduit de deux questions posées au déclarant au lieu d'être choisie librement.

### 4.1 Catégories d'incidents

| Catégorie | Exemples |
| --- | --- |
| Matériel informatique | PC, vidéoprojecteur, imprimante |
| Logiciel / Accès | Bug applicatif, perte de mot de passe |
| Réseau | Wi-Fi, accès internet, lecteur réseau |
| Infrastructures | Éclairage, chauffage, prises électriques |
| Audiovisuel | Sonorisation, écrans interactifs |
| Demande d'équipement (nouveau) | Matériel manquant, installation d'un logiciel |

Justification : les cinq premières catégories sont alignées sur les équipes d'intervention du lycée. La sixième sépare les demandes des pannes, car le MOA cite les « manques d'équipements » : mélangées aux pannes, elles fausseraient le taux de récurrence. Pas de catégorie « Autre », qui deviendrait vite la plus remplie et rendrait le reporting inutile.

### 4.2 Priorités

Le formulaire pose deux questions : « Qui est touché ? » (impact) et « Pouvez-vous travailler en attendant ? » (urgence). La priorité est suggérée à partir de cette matrice (champ « Suggérée » de la maquette W2), puis le technicien peut l'ajuster.

| Impact ↓ / Urgence → | Bloquant maintenant | Gênant | Peut attendre |
| --- | --- | --- | --- |
| Établissement, sécurité ou examen | Critique | Haute | Moyenne |
| Une salle ou une classe | Haute | Moyenne | Basse |
| Une seule personne | Moyenne | Basse | Basse |

| Priorité | Prise en charge | Critère |
| --- | --- | --- |
| Critique | Moins de 2 h | Sécurité ou blocage d'une classe |
| Haute | Moins de 1 jour | Gêne pour les cours |
| Moyenne | Moins de 3 jours | Dysfonctionnement partiel |
| Basse | Moins de 10 jours | Demande de confort |

Justification : la matrice impact × urgence est la méthode des outils professionnels cités par le MOA (GLPI, ServiceNow). Elle supprime le biais actuel où la demande la plus insistante passe en premier. Les délais restent à valider par le MOA (H5) : « moins de 2 h » engage les techniciens, il faut s'assurer qu'ils peuvent le tenir.

## 5. Maquettes des écrans principaux (wireframes)

Sept wireframes basse fidélité, un par user story majeure, repris tels quels de la V1.1. Ils seront transformés en maquettes graphiques après validation du MOA (H11).

| Écran | User stories |
| --- | --- |
| W1 — Connexion (code TOTP demandé ensuite pour un admin) | US09, US20 |
| W2 — Déclarer un incident | US01, US11 |
| W3 — Mes tickets | US02 |
| W4 — Détail d'un ticket | US12, US13 |
| W5 — Tableau de bord technicien | US04, US05, US08, US15 |
| W6 — Administration des comptes | US06 |
| W7 — Tableau de bord Direction | US07, US21 |

&#91;image: Wireframes W1 à W7, repris de la V1.1\]

Ajustement V1.2 à reporter sur W2 : le champ « Priorité » est précédé des deux questions « Qui est touché ? » et « Pouvez-vous travailler en attendant ? » (section 4.2), qui calculent la priorité suggérée.

## 6. Modélisation des données (MCD puis MLD)

Le modèle de la V1.1 est conservé (10 entités), complété en S6 par JETON\_COMPTE et CODE\_SECOURS (12 entités, voir le MLD et D-22). Le MCD est simplement redessiné sans clés étrangères, qui n'apparaissent qu'au MLD. Cardinalités « min,max » notées du côté de l'entité concernée.

&#91;embedded content: MCD · 10 entités de la V1.2 (les 2 entités ajoutées en S6 figurent au MLD)\]

**Lecture** : un utilisateur déclare 0 à n tickets, un ticket a exactement 1 déclarant ; un ticket a 0 ou 1 technicien assigné. Chaque ticket possède au moins son statut initial dans HISTORIQUE\_STATUT. NOTIFICATION trace les envois e-mail et dans l'application (US05, US08) ; JOURNAL\_AUDIT trace les actions sensibles (comptes, rôles, clôtures), avec un acteur facultatif car un échec de connexion peut venir d'un identifiant inconnu. EQUIPEMENT est rattaché à une salle et, optionnellement, à un ticket. Pas de suppression physique : `actif` sur les comptes, ticket archivé au statut « Clôturé ».

**Ajouts de la S6 (D-22)** : JETON\_COMPTE garde l'empreinte des liens d'activation et de réinitialisation (un utilisateur a 0 à n jetons, un jeton concerne exactement 1 utilisateur) ; CODE\_SECOURS garde l'empreinte des codes de secours de la double authentification (un utilisateur a 0 à n codes). UTILISATEUR reçoit `totp_dernier_creneau` (anti-rejeu des codes TOTP) et JOURNAL\_AUDIT reçoit `detail`. Le MPD (`docs/ticket_tournelle_mpd.sql`) fait référence.

Ajouts V1.2 : `impact`, `urgence` et `date_cloture` sur TICKET (section 4.2 et indicateurs), `libelle_fr` / `libelle_en` sur CATEGORIE (C2), `doit_changer_mdp` sur UTILISATEUR (vrai tant que le titulaire n'a pas choisi son mot de passe par le lien d'activation, ENF-04 du R2, D-20). L'auteur d'une ligne d'HISTORIQUE\_STATUT devient facultatif (0,1) : une clôture automatique après 5 jours ouvrés n'a pas d'auteur humain.

### 6.1 MLD

Clé primaire en gras, clé étrangère précédée de #.

- UTILISATEUR (**id\_utilisateur**, nom, prenom, email, role, langue, actif, mot\_de\_passe\_hash, totp\_secret, totp\_actif, totp\_dernier\_creneau, doit\_changer\_mdp)
- JETON\_COMPTE (**id\_jeton**, type, jeton\_hash, date\_creation, date\_expiration, date\_utilisation, #id\_utilisateur)
- CODE\_SECOURS (**id\_code**, code\_hash, date\_creation, date\_utilisation, #id\_utilisateur)
- CATEGORIE (**id\_categorie**, libelle\_fr, libelle\_en, actif)
- SALLE (**id\_salle**, nom, batiment)
- EQUIPEMENT (**id\_equipement**, type, #id\_salle)
- TICKET (**id\_ticket**, titre, description, statut, priorite, impact, urgence, date\_creation, date\_resolution, date\_cloture, #id\_declarant, #id\_assigne (null), #id\_categorie, #id\_salle, #id\_equipement (null))
- COMMENTAIRE (**id\_commentaire**, contenu, visibilite, date\_creation, #id\_ticket, #id\_auteur)
- HISTORIQUE\_STATUT (**id\_histo**, ancien\_statut, nouveau\_statut, date\_changement, motif, #id\_ticket, #id\_auteur (null))
- PIECE\_JOINTE (**id\_piece**, nom\_fichier, type\_mime, taille, chemin, date\_depot, #id\_ticket, #id\_auteur)
- NOTIFICATION (**id\_notification**, canal, lue, date\_envoi, #id\_destinataire, #id\_ticket (null))
- JOURNAL\_AUDIT (**id\_log**, date\_action, action, ip, cible\_type, cible\_id, detail, #id\_acteur (null))

Points de sécurité portés par le modèle : `mot_de_passe_hash` en Argon2id, jamais le mot de passe ; `totp_secret` chiffré ; `chemin` pointe vers un nom de fichier aléatoire stocké hors de la racine web. Le MPD (types, contraintes, index), produit en S4 et complété en S6, est dans docs/ticket_tournelle_mpd.sql.

## 7. Architecture technique retenue

La stack de la V1.1 est conservée (Symfony, MariaDB, Nginx, Twig, Bootstrap), avec des versions remontées pour rester maintenues au-delà du projet.

| Composant | Choix technique | Justification |
| --- | --- | --- |
| Back-end | PHP 8.4 / Symfony 7.4 LTS | Framework enseigné en SIO SLAM, écosystème robuste, ORM Doctrine sécurisé. Version LTS : correctifs de sécurité jusqu'en novembre 2029 ([source](https://symfony.com/blog/preparing-for-symfony-7-4-and-symfony-8-0)) |
| Base de données | MariaDB 11.8 | SGBDR open source, version fournie par Debian 13 donc mise à jour via apt |
| Front-end | Twig + Bootstrap 5 | Rendu serveur rapide, responsive par défaut, échappement automatique contre le XSS |
| Authentification | Symfony Security / Argon2id | Hachage conforme aux recommandations de l'ANSSI contre la force brute ; limitation des tentatives (login throttling) |
| Double authentification (admin) | TOTP (RFC 6238) via scheb/2fa-bundle | Fonctionne hors ligne sur un serveur interne : aucune dépendance cloud ni SMS |
| Hébergement | Debian 13 / Nginx + PHP-FPM (serveur du lycée) | Respect de C3 (pas de cloud tiers). Debian 13 est la version stable, supportée jusqu'en 2030 ; PHP 8.4 et MariaDB 11.8 y sont les versions par défaut |
| Outils projet | Git / Trello | Gestion de version rigoureuse et suivi Kanban accessible au MOA |

Pourquoi pas Debian 12 (V1.1) : son support régulier a pris fin le 12 juillet 2026, seule l'équipe LTS assure désormais les correctifs ([source](https://debian.org/News/2026/20260712)). De plus, Debian 12 fournit PHP 8.2 et MariaDB 10.11 ([source](https://endoflife.ai/article-debian-eol.html)) : PHP 8.3 aurait exigé un dépôt tiers.

### 7.1 Double authentification administrateur

- **Principe** : après le mot de passe, l'administrateur saisit un code à 6 chiffres généré par une application d'authentification (Google Authenticator, FreeOTP…). Le calcul se fait sur le téléphone, sans réseau ni SMS : compatible avec un serveur interne.
- **Activation** : à la première connexion, affichage d'un QR code (secret TOTP) ; le secret est stocké chiffré en base (`totp_secret`) et l'activation n'est validée qu'après saisie d'un premier code correct.
- **Secours** : 8 codes de récupération à usage unique, générés à l'activation ; réinitialisation de la 2FA possible par un autre administrateur, tracée dans le journal d'audit.
- **Périmètre** : obligatoire pour le rôle Administrateur ; optionnelle (hors périmètre initial) pour les autres rôles. Validation attendue du MOA (H9).

### 7.2 Comptes administrateurs (C5) — nouveau

- Chaque administrateur a un compte **nominatif** ; aucun compte « admin » générique ou partagé.
- Ce compte est **distinct** de son compte utilisateur : il ne sert qu'à administrer, conformément aux recommandations de l'ANSSI sur l'administration sécurisée.
- Mot de passe d'au moins 15 caractères + TOTP, déconnexion après 15 minutes d'inactivité.
- Chaque action d'administration est inscrite dans JOURNAL\_AUDIT, que l'application ne peut ni modifier ni supprimer.
- Revue des comptes admin à chaque départ d'un personnel.
- Le compte administrateur se distingue par son **adresse e-mail dédiée** (H14) : l'application refuse de donner le rôle administrateur à un compte existant, et l'import CSV ne crée jamais d'administrateur (D-19).
- À la première connexion, l'enrôlement TOTP est obligatoire avant tout autre accès ; un code ne sert qu'une fois, et les 8 codes de secours sont stockés hachés (D-21).

## 8. Planning et répartition dans le binôme

Le planning suit désormais le tableau de suivi global du prof : cahier des charges et R1 en fin de S3, sécurité intégrée dès S5, recette en S9. Les losanges sont les jalons ; les deux en couleur sont bloquants.

&#91;embedded content: planning · 10 séances de 4 h + oral en S11\]

| Séance | Contenu | Rendu / jalon |
| --- | --- | --- |
| S1 | Cadrage, Trello, espace collaboratif, journal Q/R | Trello partagé avec le MOA |
| S2–S3 | Cahier des charges, trame sécurité | CdC complet + R1 signé ; validation MOA écrite avant S5 |
| S4 | Retour MOA, maquettes haute fidélité, MPD, dépôt Git | R2 (exigences non fonctionnelles) |
| S5 | Serveur Debian 13, Nginx en HTTPS, BDD MariaDB, authentification Argon2id | Dépôt Git sans secret, démo du login en HTTPS |
| S6 | Comptes nominatifs, rôles (RBAC), 2FA admin, journal d'audit | Comptes admin conformes C5 |
| S7 | Déclaration et suivi des tickets, 8 statuts, historique, notifications | Test IDOR documenté |
| S8 | Tableaux de bord technicien et Direction, FR/EN, pièces jointes, durcissement, sauvegarde | Rapport de test de restauration |
| S9 | Recette fonctionnelle et campagne de tests sécurité | R3 + R4 + PV de recette ; validation MOA avant mise en service |
| S10 | Mise en production, guides utilisateurs | R5 + documentation utilisateur (C8) |
| S11 | Soutenance orale | — |

Changements par rapport à la V1.1 : la validation MOA passe de S4 à la fin de S3 ; R2 passe de S5 à S4 et R3 de S7 à S9, comme dans le tableau du prof ; la 2FA admin et la sécurité ne sont plus regroupées en S10 mais intégrées dès S5 et S6.

### 8.1 Organisation du binôme

| William Matondo Ngongo | Woodkens Guervil |
| --- | --- |
| Développement back-end (Symfony 7.4) | Développement front-end (Twig / Bootstrap) |
| Base de données et ORM Doctrine | Intégration i18n (FR/EN) |
| Sécurisation (OWASP, Argon2id, 2FA) | Maquettes, tests fonctionnels et recette |
| Administration serveur Debian / Nginx | Rédaction de la documentation utilisateur |

Travail transverse : revue croisée du code via Git, mise à jour du Trello et du journal Q/R à chaque séance. La revue croisée permet aussi à chacun de répondre au jury sur la partie de l'autre.

## 9. Analyse des risques

Neuf risques identifiés, évalués (probabilité × impact) et traités ; l'analyse cyber détaillée (actifs, surface d'attaque, scénarios) est dans le R1. Tableau repris de la V1.1, échéances recalées sur le nouveau planning.

| Réf. | Risque | Prob. | Impact | Mesure de traitement |
| --- | --- | --- | --- | --- |
| R-01 | Injection SQL / XSS / CSRF | Moyenne | Élevé | Doctrine (requêtes préparées), échappement Twig automatique, jetons CSRF Symfony sur tous les formulaires ; tests OWASP Top 10 en recette (S9, R4) |
| R-02 | Accès non autorisé (contournement des rôles, IDOR) | Moyenne | Élevé | RBAC par rôles Symfony Security + voters, contrôle côté serveur de chaque route, test IDOR documenté en S7 |
| R-03 | Vol ou devinette de mot de passe, force brute | Moyenne | Élevé | Argon2id, politique de mot de passe, limitation des tentatives, 2FA TOTP pour les administrateurs dès S6 |
| R-04 | Fuite de données personnelles (RGPD) | Faible | Élevé | Données minimales, HTTPS obligatoire, pas de cloud tiers, accès limité par rôle, journal d'audit, durée de conservation à définir avec le MOA (H8) |
| R-05 | Dépôt de fichier malveillant (photo) | Moyenne | Moyen | Liste blanche (JPG/PNG), taille max., renommage, stockage hors racine web, contrôle du type MIME réel ; test d'envoi d'un .php en S8 |
| R-06 | Indisponibilité du serveur, perte de données | Faible | Élevé | Sauvegarde quotidienne de la BDD et des pièces jointes, restauration réellement testée en S8, documentation d'exploitation |
| R-07 | Spam d'e-mails, échec d'envoi SMTP | Moyenne | Moyen | File de messages asynchrone (Messenger), notification dans l'application en secours, relais SMTP à valider (H6) |
| R-08 | Retard de planning (10 séances seulement) | Élevée | Moyen | Périmètre priorisé (MoSCoW), point d'avancement à chaque séance, Trello partagé avec le MOA, fonctionnalités « C » reportables |
| R-09 | Indisponibilité d'un membre du binôme | Faible | Moyen | Code versionné sur Git, revues croisées, documentation au fil de l'eau, compétences partagées sur les modules clés |

## 10. Hypothèses retenues

Les questions de la V1.1 ont été posées au MOA en séance. Pour chaque point ouvert, le binôme a fixé une hypothèse de travail, appliquée dans ce document ; toute réponse contraire du MOA donnera lieu à une mise à jour écrite (C9).

| N° | Point ouvert | Hypothèse retenue | Où elle s'applique |
| --- | --- | --- | --- |
| H1 | Rôle Direction | Prévu, en lecture seule : tableau de bord (tickets ouverts, délai moyen de résolution, respect des délais) et exports CSV/PDF | §2, US07, US21, W7 |
| H2 | Dimensionnement | Charge modérée (200+ comptes, usage en journée) : un serveur unique suffit ; vérification par un test de charge simple en S9 | §7, §8 |
| H3 | Création des comptes | Import CSV de la liste des personnels puis e-mail d'activation ; création manuelle pour les arrivées en cours d'année | §2, US06 |
| H4 | Rôles | Les référents numériques ont le rôle Technicien ; au moins 2 administrateurs nominatifs, pour que l'un puisse réinitialiser la 2FA de l'autre | §7.1, §7.2 |
| H5 | Délais de prise en charge | 2 h / 1 j / 3 j / 10 j selon la priorité, comptés en heures et jours ouvrés | §4.2 |
| H6 | Envoi des e-mails | Relais SMTP de l'établissement ; à défaut, notifications dans l'application uniquement | §3.1, R-07 |
| H7 | Serveur | Machine du lycée sous Debian 13, administrée par le binôme ; certificat auto-signé ou émis par une autorité interne ; sauvegarde sur un support séparé | §7, C3 |
| H8 | Conservation des données (RGPD) | Journaux de sécurité conservés 6 mois (comme dans le R1) ; tickets clôturés conservés pour les statistiques, durée à fixer par la direction | R-04 |
| H9 | Double authentification | Code TOTP par application d'authentification, obligatoire pour les administrateurs | §7.1 |
| H10 | Référentiels | Liste initiale des salles, bâtiments et équipements saisie par l'administrateur, modifiable ensuite | §2, US18 |
| H11 | Maquettes | Wireframes W1 à W7 retenus ; thème Bootstrap sobre, logo du lycée s'il est fourni | §5 |
| H12 | Recette | En S9, avec le binôme et les évaluateurs comme utilisateurs tests | §8 |
| H13 | Incidents « Infrastructures » | Déclarés dans Ticket'Tournelle, puis traités ou transmis par les référents au service concerné | §4.1 |
| H14 | Adresse des comptes administrateurs | Le compte administrateur d'une personne utilise une adresse dédiée par sous-adressage (`prenom.nom+admin@…`), qui arrive dans sa boîte habituelle ; à valider par le MOA. Si la messagerie du lycée ne l'accepte pas, un alias `adm-prenom.nom@…` est créé : la convention est un réglage de l'application (D-19) | §7.2, C5, H6 |

## 11. Conformité aux contraintes du MOA

Chacune des neuf contraintes est couverte ; C4 et C5 sont désormais traitées séparément, avec la preuve attendue.

| Réf. | Contrainte MOA | Réponse apportée |
| --- | --- | --- |
| C1 | Comptes nominatifs | Compte unique par personne, créé par l'admin (e-mail d'activation), aucun compte partagé ; désactivation au départ |
| C2 | Interface bilingue | Internationalisation complète FR/EN (interface, e-mails, référentiels) via Symfony Translation ; langue mémorisée dans le profil |
| C3 | Hébergement interne | Serveur Debian 13 de l'établissement, installé et administré par le binôme, sans cloud tiers ; seul le port HTTPS ouvert aux utilisateurs |
| C4 | Sécurité selon les bonnes pratiques + preuves | RBAC, protection CSRF/XSS/SQLi, Argon2id, limitation des tentatives, contrôle d'accès côté serveur (sections 7 et 9, détail dans le R1). Preuve : chaque mesure a son test et sa capture dans le rapport de recette sécurité (R4) |
| C5 | Comptes administrateurs | Comptes admin nominatifs, distincts du compte utilisateur, 2FA TOTP obligatoire, actions journalisées (section 7.2) |
| C6 | Pilotage Trello | Tableau partagé avec le MOA, mis à jour à chaque séance |
| C7 | Espace collaboratif | Espace partagé pour les ressources et le suivi documentaire ; versioning du code sur Git |
| C8 | Livrable et documentation | Documentation utilisateur en trois versions (professeur, personnel, administrateur) |
| C9 | Cadre AP | Travail en binôme, jalons validés par écrit, questions posées au MOA en séance et hypothèses retenues consignées (section 10) |

**Bon pour accord MOA** : M. Lorant-Raze / M. Chamayou — Date et signature : \_\_\_\_ / \_\_\_\_ / 2026
