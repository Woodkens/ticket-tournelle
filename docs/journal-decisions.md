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
