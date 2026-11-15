# Journal de décisions techniques et cyber — Ticket'Tournelle (R3)

Binôme : William Matondo Ngongo · Woodkens Guervil. Une entrée par choix notable, ajoutée avec la skill `/decision`. Les entrées ne sont jamais modifiées : une décision qui change donne une nouvelle entrée.

### D-01 — Debian 13 plutôt que Debian 12
- **Date** : 14/11/2026
- **Contexte** : la V1.1 du cahier des charges prévoyait Debian 12 avec PHP 8.3 et MariaDB 10.11.
- **Options étudiées** : Debian 12 (support LTS seulement depuis juillet 2026) ; Debian 13 (version stable).
- **Choix** : Debian 13 avec ses paquets par défaut (PHP 8.4, MariaDB 11.8).
- **Justification** : Debian 12 ne reçoit plus le support régulier et fournit PHP 8.2 ; PHP 8.3 y aurait exigé un dépôt tiers. Debian 13 est maintenue jusqu'en 2030.
- **Lien** : ENF-22, CdC V1.2 §7.
- **Auteur** : binôme

### D-02 — Argon2id pour le hachage des mots de passe
- **Date** : 14/11/2026
- **Contexte** : choix de l'algorithme de stockage des mots de passe.
- **Options étudiées** : MD5/SHA1 ; bcrypt ; Argon2id.
- **Choix** : Argon2id (hasher « auto » de Symfony).
- **Justification** : MD5 et SHA1 sont trop rapides et cassables par force brute. Argon2id est volontairement lent et gourmand en mémoire ; bcrypt aurait été acceptable mais tronque les mots de passe au-delà de 72 octets.
- **Lien** : ENF-01, R1 §6.2.
- **Auteur** : binôme

### D-03 — Auteur facultatif dans HISTORIQUE_STATUT
- **Date** : 14/11/2026
- **Contexte** : la clôture automatique après 5 jours ouvrés n'a pas d'auteur humain.
- **Options étudiées** : compte « système » fictif ; auteur facultatif (NULL).
- **Choix** : `id_auteur` NULL = action du système ; cardinalité 0,1 dans le MCD.
- **Justification** : un faux compte système brouillerait l'imputabilité et pourrait être utilisé à mauvais escient ; NULL dit clairement « aucun humain ».
- **Lien** : CdC V1.2 §3 et §6.
- **Auteur** : binôme

### D-04 — Environnement de développement WSL2 + Debian 13
- **Date** : 14/11/2026
- **Contexte** : le poste de développement (Windows 11) n'a que PHP 8.3 via MAMP, sans MariaDB, alors que la stack figée est PHP 8.4 / MariaDB 11.8 sur Debian 13.
- **Options étudiées** : MAMP (PHP 8.3) ; Docker Compose ; WSL2 avec Debian 13.
- **Choix** : WSL2 + Debian 13, pile installée avec apt (php8.4-fpm, nginx, mariadb-server), projet dans `~/ticket-tournelle` côté Linux.
- **Justification** : on développe sur exactement la même pile que le futur serveur du lycée, et chaque commande d'installation est réutilisée telle quelle en S5. MAMP donnerait une autre version de PHP, Docker ajouterait un outil de plus à justifier.
- **Lien** : ENF-22, ENF-32, CdC V1.2 §7, R1 §6.1.
- **Auteur** : Woodkens

### D-05 — Liste minimale de paquets Symfony
- **Date** : 14/11/2026
- **Contexte** : le projet Symfony peut être créé avec le pack complet `webapp` ou à partir du squelette minimal.
- **Options étudiées** : pack `webapp` complet ; squelette + paquets choisis ; squelette seul.
- **Choix** : `symfony/skeleton` 7.4 + twig, security, orm-pack, migrations, form, validator, translation, security-csrf, monolog, asset-mapper ; en développement seulement : maker, phpunit, profiler.
- **Justification** : chaque dépendance est une surface d'attaque (CVE possibles) et doit pouvoir être justifiée à l'oral ; mailer, messenger et 2FA seront ajoutés dans la séance qui en a besoin. Les versions sont figées dans `composer.lock` et vérifiées par `composer audit`.
- **Lien** : ENF-23, R1 §2 (dépendances tierces), CLAUDE.md (stack figée).
- **Auteur** : Woodkens

### D-06 — Le MPD SQL reste la source de vérité du schéma
- **Date** : 14/11/2026
- **Contexte** : le schéma existe déjà dans `docs/ticket_tournelle_mpd.sql`, et Doctrine peut aussi générer le schéma à partir des entités.
- **Options étudiées** : laisser Doctrine générer un schéma à sa façon ; garder le MPD comme référence et y aligner les entités.
- **Choix** : le MPD est la référence ; les entités Doctrine (à partir de S5) reprennent ses noms de tables et de colonnes (`id_ticket`…), la migration le reproduit et `doctrine:schema:validate` vérifie l'accord.
- **Justification** : le MPD a été conçu et validé avec ses contraintes de sécurité (CHECK, RESTRICT, droits SQL) ; un schéma généré automatiquement les perdrait sans qu'on s'en aperçoive.
- **Lien** : CdC V1.2 §6, R1 §4 (moindre privilège), ENF-16.
- **Auteur** : Woodkens

### D-07 — Contrainte TOTP : cohérence en base, obligation dans l'application
- **Date** : 14/11/2026
- **Contexte** : l'ancienne contrainte `ck_admin_totp` laissait croire que la base imposait la 2FA aux administrateurs, ce qu'elle ne faisait pas.
- **Options étudiées** : garder l'ancienne contrainte ; imposer en base un secret TOTP pour tout admin ; ne contrôler en base que la cohérence et imposer l'obligation dans l'application.
- **Choix** : `CONSTRAINT ck_totp_secret CHECK (totp_actif = FALSE OR totp_secret IS NOT NULL)` ; la 2FA obligatoire des admins est imposée par scheb/2fa-bundle en S6.
- **Justification** : un admin doit pouvoir exister avant d'avoir scanné son QR code, donc la base ne peut pas exiger la 2FA ; elle garantit seulement qu'une 2FA active a toujours un secret. Le vrai contrôle se fait au login, côté application.
- **Lien** : ENF-05, CdC V1.2 §7.1, MPD (table utilisateur).
- **Auteur** : Woodkens

### D-08 — Hasher Argon2id déclaré explicitement (complète D-02)
- **Date** : 14/11/2026
- **Contexte** : en testant le projet Symfony 7.4, le hasher « auto » prévu par D-02 a produit des empreintes bcrypt (`$2y$`) et non Argon2id.
- **Options étudiées** : garder « auto » (bcrypt) et corriger les documents ; déclarer `algorithm: argon2id` dans `config/packages/security.yaml`.
- **Choix** : `algorithm: argon2id` explicite, vérifié par un test automatique (`tests/Security/PasswordHasherTest.php`) qui exige le préfixe `$argon2id$`.
- **Justification** : « auto » laisse Symfony choisir et ce choix peut changer d'une version à l'autre ; écrire l'algorithme rend l'exigence ENF-01 explicite et le test la prouve à chaque exécution.
- **Lien** : ENF-01, R1 §6.2, D-02. Documents mis à jour : CLAUDE.md, R1 §6.2, R2 ENF-01.
- **Auteur** : Woodkens

### D-09 — Aucune hiérarchie entre les rôles
- **Date** : 15/11/2026
- **Contexte** : Symfony permet de faire hériter un rôle d'un autre (`role_hierarchy`) ; il fallait décider si ROLE_ADMIN ou ROLE_TECHNICIEN contiennent ROLE_USER.
- **Options étudiées** : hiérarchie complète (admin > technicien > utilisateur) ; technicien hérite seulement d'utilisateur ; aucun héritage.
- **Choix** : aucun héritage ; un compte a un seul rôle, « être connecté » se vérifie avec `IS_AUTHENTICATED_FULLY`, et les Voters appliqueront la matrice en S6/S7.
- **Justification** : dans la matrice §2.1, aucun rôle ne contient tous les droits d'un autre (l'admin ne crée pas de ticket, le technicien n'annule pas, la direction ne voit pas les tickets). Un héritage donnerait donc des droits en trop sans qu'on le voie.
- **Lien** : CdC V1.2 §2.1, C5, R1 §4 (moindre privilège).
- **Auteur** : Woodkens

### D-10 — Jeton CSRF classique (lié à la session) pour la connexion et la déconnexion
- **Date** : 15/11/2026
- **Contexte** : la recette Symfony 7.4 configure par défaut des jetons CSRF « sans état » (`stateless_token_ids`) pour `authenticate` et `logout`.
- **Options étudiées** : garder les jetons sans état (contrôle des en-têtes Origin/Referer et cookie posé en JavaScript) ; jeton classique tiré au hasard et gardé dans la session.
- **Choix** : jeton classique lié à la session pour la connexion et la déconnexion ; la déconnexion se fait uniquement en POST.
- **Justification** : le principe s'explique en une phrase (le serveur compare le jeton du formulaire à celui de la session) et se teste simplement avec un jeton absent ou faux. Le mode sans état dépend d'un script JavaScript que le projet n'a pas.
- **Lien** : R1 §6.1 (protection CSRF), CLAUDE.md (jeton CSRF sur tous les formulaires), `config/packages/csrf.yaml`.
- **Auteur** : Woodkens

### D-11 — Limitation des essais de connexion : comportement standard de Symfony
- **Date** : 15/11/2026
- **Contexte** : ENF-03 demandait de bloquer « le compte et l'adresse IP » après 5 échecs en 15 minutes ; le `login_throttling` de Symfony compte par couple e-mail + IP (5 essais) et par IP seule (25 essais).
- **Options étudiées** : comportement standard de Symfony ; limiteur maison qui verrouille le compte quelle que soit l'adresse IP.
- **Choix** : comportement standard (`max_attempts: 5`, `interval: 15 minutes`), avec le composant officiel `symfony/rate-limiter` ajouté avec l'accord du binôme ; le blocage dure jusqu'à la fin de la fenêtre de 15 minutes.
- **Justification** : verrouiller un compte quelle que soit l'IP permettrait à n'importe qui de bloquer un collègue en tapant 5 fois un faux mot de passe. Le couple e-mail + IP arrête la force brute sans offrir ce moyen de nuire.
- **Lien** : ENF-03, R1 §6.2, US09, R-03. Écart signalé : ENF-03 (R2) et R1 §6.2 mis à jour.
- **Auteur** : Woodkens

### D-12 — Base de test dédiée et compte SQL `tt_test`
- **Date** : 15/11/2026
- **Contexte** : les tests automatiques doivent repartir d'une base vide, mais le compte `tt_app` n'a pas le droit DELETE et ne doit pas l'avoir.
- **Options étudiées** : donner DELETE à `tt_app` ; tests sur SQLite ; base `tickettournelle_test` avec un compte `tt_test` séparé.
- **Choix** : base `tickettournelle_test` créée à partir du MPD et compte `tt_test` (SELECT, INSERT, UPDATE, DELETE sur cette base uniquement), mot de passe dans `.env.test.local` hors Git, le tout créé par `deploy/dev/create-test-db.sh`.
- **Justification** : les tests tournent sur le vrai schéma MariaDB sans affaiblir le compte de l'application ni toucher aux vraies données. Limite connue : le moindre privilège de `tt_app` se vérifie à la main, pas par les tests.
- **Lien** : ENF-16, R1 §4 (moindre privilège), ENF-11, D-06.
- **Auteur** : Woodkens

### D-13 — Certificat auto-signé (openssl) pour le HTTPS du poste de développement
- **Date** : 15/11/2026
- **Contexte** : le login doit être démontré en HTTPS en local, et le cookie de session est marqué Secure.
- **Options étudiées** : mkcert (autorité locale reconnue par le navigateur) ; certificat auto-signé créé avec openssl.
- **Choix** : certificat auto-signé openssl, rangé dans `/etc/ssl` hors du dépôt ; Nginx redirige le port 80 vers le 443 ; HSTS seulement dans le modèle de production.
- **Justification** : aucun outil en plus, et c'est le cas prévu pour le serveur du lycée (H7) : le chiffrement est le même, seul l'avertissement du navigateur diffère. HSTS en développement forcerait HTTPS sur « localhost » pour tous les autres projets du poste.
- **Lien** : ENF-29, ENF-06, ENF-11, CdC V1.2 H7 et C3, R1 annexe.
- **Auteur** : Woodkens

### D-14 — Expiration des sessions par un écouteur de l'application
- **Date** : 15/11/2026
- **Contexte** : ENF-08 impose 30 minutes d'inactivité (15 pour un administrateur) et 8 heures au maximum ; le réglage PHP `gc_maxlifetime` seul ne garantit pas ces délais et ne distingue pas les rôles.
- **Options étudiées** : s'appuyer seulement sur `gc_maxlifetime` ; durée du cookie ; écouteur qui compare l'heure à chaque requête.
- **Choix** : `SessionExpirationSubscriber` note l'heure de connexion et de dernière activité dans la session, puis détruit la session et renvoie vers la connexion si un délai est dépassé (tracé `SESSION_EXPIRED`).
- **Justification** : le nettoyage des sessions par PHP est aléatoire et un cookie peut être rejoué ; seul un contrôle côté serveur à chaque requête est fiable. Le délai par rôle prépare déjà les 15 minutes des administrateurs.
- **Lien** : ENF-08, ENF-09, R1 §6.3, CdC V1.2 §7.2.
- **Auteur** : Woodkens

### D-15 — Pas de compte administrateur avant la TOTP (S6)
- **Date** : 15/11/2026
- **Contexte** : la commande `app:create-user` crée le premier compte en S5, alors que la double authentification des administrateurs n'arrive qu'en S6 (D-07).
- **Options étudiées** : autoriser la création d'un admin dès la S5 ; refuser ROLE_ADMIN jusqu'à la S6.
- **Choix** : la commande refuse ROLE_ADMIN ; le mot de passe est saisi en caché, jamais en argument, avec 12 caractères minimum, différent de l'identifiant, et une solidité estimée sur le serveur (`PasswordStrength`).
- **Justification** : ainsi il n'existe à aucun moment un administrateur protégé par un simple mot de passe. La solidité est calculée localement pour ne dépendre d'aucun service extérieur ; la liste des mots de passe déjà fuités (ENF-02) reste à traiter en S6.
- **Lien** : ENF-02, ENF-05, C5, D-07, CdC V1.2 §7.2.
- **Auteur** : Woodkens

### D-16 — Validation du schéma limitée aux tables déjà mappées
- **Date** : 15/11/2026
- **Contexte** : `doctrine:schema:validate` proposait de supprimer les 8 tables du MPD qui n'ont pas encore d'entité, et signalait un écart permanent sur `journal_audit.date_action` (DATETIME(3)).
- **Options étudiées** : mapper les 10 tables dès la S5 ; ignorer le résultat de la validation ; filtrer les tables comparées (`schema_filter`) et recopier la valeur par défaut telle que Doctrine la lit.
- **Choix** : `schema_filter` limité à `utilisateur` et `journal_audit`, à élargir à chaque séance ; pour `date_action`, la colonne est remplie par MariaDB et le mapping recopie la valeur par défaut lue par Doctrine.
- **Justification** : la validation reste au vert et garde donc son rôle d'alarme si une entité s'écarte du MPD. Doctrine ne connaît pas la précision à la milliseconde : le MPD reste la seule définition exacte.
- **Lien** : D-06, ENF-15, ENF-16, MPD (table journal_audit).
- **Auteur** : Woodkens

### D-17 — Aucun script chargé depuis un CDN (polyfill importmap retiré)
- **Date** : 15/11/2026
- **Contexte** : AssetMapper ajoutait par défaut à chaque page un script de secours (`es-module-shims`) chargé depuis `ga.jspm.io`, pour les navigateurs qui ne connaissent pas les « importmap ».
- **Options étudiées** : garder le script du CDN ; héberger une copie locale du script ; le retirer (`importmap_polyfill: false`).
- **Choix** : script retiré ; un test vérifie que l'écran de connexion, l'accueil et la page 404 ne chargent aucune adresse externe.
- **Justification** : un script venu d'un serveur tiers pourrait être modifié pour voler des sessions, et il révèle à ce tiers qui utilise le site. Les navigateurs visés (versions récentes de Firefox, Chrome, Edge) gèrent les importmap sans aide.
- **Lien** : C3 (pas de service tiers), ENF-26, ENF-29 (CSP en S8), ENF-31, R1 §2 (dépendances tierces).
- **Auteur** : Woodkens

### D-18 — Temps de réponse du login égalisé
- **Date** : 15/11/2026
- **Contexte** : le message d'erreur est générique, mais un e-mail inconnu ou un compte désactivé répondait en 12 ms environ contre 90 ms pour un compte existant, car Argon2id (77 ms mesurés) n'était calculé que dans ce dernier cas.
- **Options étudiées** : ne rien faire ; ajouter une attente fixe ou aléatoire ; calculer une empreinte Argon2id factice quand le mot de passe n'a pas été vérifié.
- **Choix** : `TempsDeReponseConnexionSubscriber` calcule une empreinte Argon2id sur une valeur factice pour un e-mail inconnu ou un compte désactivé, avec les mêmes réglages que les vrais mots de passe.
- **Justification** : sans cela, on devine quels comptes existent avec un simple chronomètre, ce qui annule l'intérêt du message générique. Refaire le vrai calcul suit automatiquement les réglages d'Argon2id, ce qu'une attente fixe ne ferait pas ; le test compte les calculs déclenchés, car mesurer des durées dans un test est instable.
- **Lien** : ENF-03, ENF-01, R1 §2 (page de connexion) et annexe, R-03, D-08.
- **Auteur** : Woodkens

### D-19 — Comptes administrateurs séparés par une adresse e-mail dédiée
- **Date** : 15/11/2026
- **Contexte** : C5 impose un compte administrateur distinct du compte utilisateur de la même personne, alors que l'e-mail est l'identifiant de connexion et qu'il est unique en base.
- **Options étudiées** : ajouter une colonne `identifiant` distincte de l'e-mail ; adresse dédiée par sous-adressage (`prenom.nom+admin@…`) ; alias créé par le lycée (`adm-prenom.nom@…`).
- **Choix** : adresse dédiée, dont la forme est un paramètre de configuration (`app.comptes.motif_email_admin`, sous-adressage par défaut) ; un compte existant ne peut pas être promu administrateur, et l'import CSV n'en crée jamais.
- **Justification** : la contrainte UNIQUE de la base garantit déjà deux comptes séparés, sans toucher au schéma ni à l'écran de connexion, et les e-mails arrivent dans la boîte habituelle. Comme la convention est un réglage, on passe à un alias sans modifier le code si la messagerie du lycée refuse le sous-adressage.
- **Lien** : C5, CdC V1.2 §7.2 et H14 (hypothèse à valider par le MOA), R1 §3.3, `config/services.yaml`.
- **Auteur** : William

### D-20 — Activation par lien, sans mot de passe provisoire
- **Date** : 15/11/2026
- **Contexte** : ENF-04 prévoyait un mot de passe provisoire à changer à la première connexion ; il aurait fallu le transmettre à 200 personnes.
- **Options étudiées** : mot de passe provisoire envoyé par e-mail ; mot de passe provisoire remis par l'administrateur ; lien d'activation à usage unique, le titulaire choisissant son mot de passe.
- **Choix** : lien d'activation (72 h) et lien de réinitialisation (30 min) à usage unique, jeton de 256 bits dont seule l'empreinte SHA-256 est stockée, tout nouveau lien annulant les précédents ; `doit_changer_mdp = vrai` signifie « mot de passe pas encore choisi » et la connexion est alors refusée.
- **Justification** : aucun mot de passe ne circule et personne d'autre que le titulaire ne le connaît. Un hachage rapide suffit pour le jeton, car il est aléatoire et bien trop long pour être deviné, contrairement à un mot de passe choisi par un humain.
- **Lien** : ENF-04, US06, US10, C1, R1 §6.2. Écart signalé : ENF-04 (R2), R1 §4 et §6.2, MPD mis à jour.
- **Auteur** : Woodkens

### D-21 — TOTP : secret chiffré, codes de secours hachés, un code ne sert qu'une fois
- **Date** : 15/11/2026
- **Contexte** : scheb/2fa-bundle vérifie les codes TOTP, mais il range le secret en clair, n'empêche pas de rejouer un code et ne limite pas les essais.
- **Options étudiées** : utiliser le bundle tel quel ; protection contre le rejeu par le cache du bundle ; notre classe de vérification avec secret chiffré et dernier créneau noté en base.
- **Choix** : `AuthentificateurTotp` remplace la vérification du bundle : secret chiffré avec libsodium (clé `TOTP_ENCRYPTION_KEY` dans `.env.local`), colonne `totp_dernier_creneau` mise à jour en une seule requête, 5 codes faux en 15 minutes bloquent la saisie, 8 codes de secours hachés en Argon2id, enrôlement forcé à la première connexion.
- **Justification** : le secret doit être relu pour recalculer le code, donc on le chiffre au lieu de le hacher, et une base volée sans la clé ne donne rien. Noter le créneau en base, et non dans un cache, garantit qu'un code vu par-dessus l'épaule ne peut pas resservir, même si le cache est vidé.
- **Lien** : ENF-05, C5, CdC V1.2 §7.1, R1 §3.3, D-07, MPD (`ck_totp_secret`).
- **Auteur** : William

### D-22 — Première migration du schéma
- **Date** : 15/11/2026
- **Contexte** : le MPD ne prévoyait rien pour les liens d'activation, les codes de secours, l'anti-rejeu TOTP ni le détail d'une action d'audit.
- **Options étudiées** : tout ranger dans des colonnes de `utilisateur` ; deux tables dédiées et deux colonnes ; calculer la « dernière connexion » avec une colonne de plus ou à partir du journal.
- **Choix** : tables `jeton_compte` et `code_secours`, colonnes `utilisateur.totp_dernier_creneau` et `journal_audit.detail` ; le MPD est modifié d'abord, la migration le reproduit et se lance avec `tt_migrate` ; la dernière connexion est lue dans `journal_audit`, sans colonne en plus.
- **Justification** : des tables dédiées gardent l'historique sans jamais supprimer de ligne (un jeton ou un code utilisé est seulement daté). `tt_migrate` n'a pas le droit GRANT : c'est root qui donne à `tt_app` ses droits sur les nouvelles tables, sans DELETE, avec `deploy/dev/grant-app.sh`.
- **Lien** : D-06, D-16, ENF-16, CdC V1.2 §6, R1 §4 (moindre privilège).
- **Auteur** : William

### D-23 — Mots de passe fuités : liste locale plutôt qu'un service en ligne
- **Date** : 15/11/2026
- **Contexte** : ENF-02 demande de refuser les mots de passe courants ou déjà fuités (point laissé ouvert par D-15).
- **Options étudiées** : contrainte `NotCompromisedPassword` de Symfony (appel au service Have I Been Pwned, en k-anonymat) ; liste locale de mots de passe courants.
- **Choix** : liste locale `config/securite/mots-de-passe-courants.txt` (SecLists, licence MIT, 46 000 entrées de 12 caractères ou plus), complétée par le refus du nom, du prénom, de l'identifiant et des mots du lycée, et par `PasswordStrength` au seuil « faible » (60 bits).
- **Justification** : le service en ligne couvre beaucoup plus de mots de passe, mais chaque choix de mot de passe appellerait un serveur tiers, ce que C3 et D-17 interdisent, et une panne obligerait à tout accepter ou tout refuser. Le seuil de solidité supérieur de Symfony refuserait tout mot de passe de 12 caractères, même aléatoire, et contredirait ENF-02.
- **Lien** : ENF-02, C3, D-15, D-17, R1 §6.2. Écart signalé : ENF-02 (R2) et R1 §6.2 mis à jour.
- **Auteur** : Woodkens

### D-24 — E-mails bilingues, sans SMTP en développement, envoyés après la réponse
- **Date** : 15/11/2026
- **Contexte** : les e-mails d'activation et de réinitialisation doivent exister en français et en anglais (C2), et « mot de passe oublié » ne doit pas révéler quels comptes existent.
- **Options étudiées** : e-mail dans la langue de la page ; e-mail dans la langue du profil du destinataire ; envoi pendant la requête ou après la réponse.
- **Choix** : Symfony Mailer avec des gabarits Twig traduits, dans la langue du profil ; `MAILER_DSN=null://null` en développement (e-mails visibles dans le profiler) ; pour « mot de passe oublié », le lien et l'e-mail sont préparés après l'envoi de la réponse hors mode debug, et la page répond la même chose dans tous les cas.
- **Justification** : c'est le destinataire qui lit l'e-mail, pas l'administrateur qui crée le compte. Préparer l'e-mail après la réponse évite qu'un chronomètre distingue un compte existant d'un compte inconnu, comme pour la connexion (D-18).
- **Lien** : C2, ENF-27, ENF-04, R1 §2 (notifications e-mail), H6, R-07, D-18.
- **Auteur** : Woodkens

### D-25 — Import CSV : aperçu avant écriture et « tout ou rien »
- **Date** : 15/11/2026
- **Contexte** : les comptes des 200 personnels sont créés à partir d'un fichier CSV (C1, H3), qui peut contenir des erreurs.
- **Options étudiées** : créer les lignes valides et ignorer les autres ; refuser tout le fichier à la première erreur sans détail ; aperçu avec les erreurs ligne par ligne, puis création de tous les comptes ou d'aucun.
- **Choix** : l'envoi du fichier ne fait que le vérifier et montre un aperçu ; la confirmation revérifie les lignes puis crée tous les comptes dans une seule transaction ; l'import refuse le rôle administrateur et les adresses d'administrateur.
- **Justification** : avec « tout ou rien », l'administrateur sait exactement ce qui est en base : soit le fichier entier, soit rien, jamais un import à moitié fait à rattraper à la main. Refuser les administrateurs à l'import évite qu'un simple fichier donne les pleins pouvoirs.
- **Lien** : C1, C5, CdC V1.2 H3, US06, D-19.
- **Auteur** : Woodkens

### D-26 — Dates enregistrées en temps universel (UTC), affichées à l'heure de Paris
- **Date** : 15/11/2026
- **Contexte** : un test sur l'expiration des liens a montré une heure d'écart entre PHP (UTC) et MariaDB (heure du serveur, CET) : les dates posées par la base (journal d'audit) et celles écrites par PHP (expiration des jetons) ne parlaient pas de la même heure.
- **Options étudiées** : tout régler à l'heure de Paris ; tout enregistrer en UTC et convertir à l'affichage ; laisser l'écart.
- **Choix** : PHP est fixé en UTC dans `src/Kernel.php`, MariaDB passe en UTC à chaque connexion (`SET time_zone` dans `doctrine.yaml`) et Twig affiche les dates à l'heure de Paris (`twig.date.timezone`).
- **Justification** : l'UTC n'a pas de changement d'heure, donc une durée (72 h, 30 min, 15 min d'inactivité) se calcule toujours juste et le journal d'audit n'a jamais d'heure en double à la fin d'octobre. Seul l'affichage dépend du lieu, et il est réglé à un seul endroit.
- **Lien** : ENF-04, ENF-08, ENF-15, D-20, MPD (`journal_audit.date_action`).
- **Auteur** : binôme
