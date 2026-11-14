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
