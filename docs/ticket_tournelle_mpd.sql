-- =====================================================================
--  Ticket'Tournelle — MPD (Modèle Physique de Données)
--  SGBD cible : MariaDB 11.8 LTS (Debian 13)
--  Source     : MLD du cahier des charges V1.2 (section 6.1)
--  Binôme     : William Matondo Ngongo · Woodkens Guervil
--
--  Conventions :
--   - moteur InnoDB (clés étrangères + transactions), jeu utf8mb4 (FR/EN, accents)
--   - aucune suppression physique : toutes les FK sont en ON DELETE RESTRICT
--   - les secrets (mots de passe des comptes SQL) ne sont JAMAIS écrits ici :
--     ils sont saisis au moment de l'installation (voir fin du script)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS tickettournelle
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tickettournelle;

-- ---------------------------------------------------------------------
-- Référentiels
-- ---------------------------------------------------------------------
CREATE TABLE categorie (
  id_categorie  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  libelle_fr    VARCHAR(80)  NOT NULL,
  libelle_en    VARCHAR(80)  NOT NULL,
  actif         BOOLEAN      NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id_categorie),
  UNIQUE KEY uq_categorie_fr (libelle_fr),
  UNIQUE KEY uq_categorie_en (libelle_en)
) ENGINE=InnoDB;

CREATE TABLE salle (
  id_salle   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom        VARCHAR(50) NOT NULL,
  batiment   VARCHAR(50) NOT NULL,
  PRIMARY KEY (id_salle),
  UNIQUE KEY uq_salle (batiment, nom)          -- pas deux fois la même salle dans un bâtiment
) ENGINE=InnoDB;

CREATE TABLE equipement (
  id_equipement  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type           VARCHAR(80)  NOT NULL,
  id_salle       SMALLINT UNSIGNED NOT NULL,   -- installé dans (1,1)
  PRIMARY KEY (id_equipement),
  KEY idx_equipement_salle (id_salle),
  CONSTRAINT fk_equipement_salle FOREIGN KEY (id_salle)
    REFERENCES salle (id_salle) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Comptes
-- ---------------------------------------------------------------------
CREATE TABLE utilisateur (
  id_utilisateur     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom                VARCHAR(100) NOT NULL,
  prenom             VARCHAR(100) NOT NULL,
  email              VARCHAR(180) NOT NULL,                  -- identifiant de connexion (C1)
  role               ENUM('ROLE_USER','ROLE_TECHNICIEN','ROLE_ADMIN','ROLE_DIRECTION')
                     NOT NULL DEFAULT 'ROLE_USER',           -- Security by Default : rôle le plus faible
  langue             ENUM('fr','en') NOT NULL DEFAULT 'fr',   -- C2
  actif              BOOLEAN      NOT NULL DEFAULT TRUE,      -- désactivation, jamais de suppression
  mot_de_passe_hash  VARCHAR(255) NOT NULL,                   -- Argon2id (ENF-01)
  doit_changer_mdp   BOOLEAN      NOT NULL DEFAULT TRUE,      -- TRUE = le titulaire n'a pas encore choisi son mot de passe : connexion refusée (ENF-04, D-20)
  totp_secret        VARCHAR(255) NULL,                       -- stocké chiffré (ENF-05)
  totp_actif         BOOLEAN      NOT NULL DEFAULT FALSE,
  totp_dernier_creneau INT UNSIGNED NULL,                     -- dernier créneau de 30 s accepté : un code TOTP ne sert qu'une fois (anti-rejeu, S6)
  PRIMARY KEY (id_utilisateur),
  UNIQUE KEY uq_utilisateur_email (email),
  KEY idx_utilisateur_role (role, actif),
  -- Cohérence TOTP : une 2FA active a forcément un secret (D-07).
  -- La 2FA OBLIGATOIRE des administrateurs n'est pas garantie par la base
  -- (un admin doit pouvoir exister avant d'avoir scanné son QR code) :
  -- elle est imposée par l'application, scheb/2fa-bundle, en S6 (ENF-05).
  CONSTRAINT ck_totp_secret CHECK (totp_actif = FALSE OR totp_secret IS NOT NULL)
) ENGINE=InnoDB;

-- Liens d'activation et de réinitialisation du mot de passe (S6, ENF-04, US06, US10).
-- Le jeton envoyé par e-mail n'est JAMAIS stocké : seule son empreinte SHA-256 l'est.
-- Usage unique : date_utilisation est remplie quand le lien a servi ou a été annulé.
CREATE TABLE jeton_compte (
  id_jeton          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type              ENUM('activation','reinitialisation') NOT NULL,
  jeton_hash        CHAR(64)     NOT NULL,          -- SHA-256 en hexadécimal du jeton aléatoire (256 bits)
  date_creation     DATETIME     NOT NULL,
  date_expiration   DATETIME     NOT NULL,          -- activation : 72 h ; réinitialisation : 30 min
  date_utilisation  DATETIME     NULL,              -- NULL = encore utilisable
  id_utilisateur    INT UNSIGNED NOT NULL,          -- concerne (1,1)
  PRIMARY KEY (id_jeton),
  UNIQUE KEY uq_jeton_hash (jeton_hash),
  KEY idx_jeton_utilisateur (id_utilisateur, type),
  CONSTRAINT fk_jeton_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Codes de secours de la double authentification (S6, ENF-05) : 8 par administrateur.
-- Stockés hachés en Argon2id comme les mots de passe ; usage unique (date_utilisation).
CREATE TABLE code_secours (
  id_code           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_hash         VARCHAR(255) NOT NULL,          -- Argon2id, jamais le code lui-même
  date_creation     DATETIME     NOT NULL,
  date_utilisation  DATETIME     NULL,              -- NULL = encore utilisable ; rempli = utilisé ou révoqué
  id_utilisateur    INT UNSIGNED NOT NULL,          -- appartient à (1,1)
  PRIMARY KEY (id_code),
  KEY idx_code_utilisateur (id_utilisateur, date_utilisation),
  CONSTRAINT fk_code_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Cœur métier
-- ---------------------------------------------------------------------
CREATE TABLE ticket (
  id_ticket        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titre            VARCHAR(150) NOT NULL,
  description      TEXT         NOT NULL,
  statut           ENUM('nouveau','assigne','en_cours','en_attente','resolu','cloture','rejete','annule')
                   NOT NULL DEFAULT 'nouveau',
  priorite         ENUM('critique','haute','moyenne','basse') NOT NULL,
  impact           ENUM('etablissement','salle','personne')   NOT NULL,   -- matrice 4.2
  urgence          ENUM('bloquant','genant','peut_attendre')   NOT NULL,   -- matrice 4.2
  date_creation    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_resolution  DATETIME     NULL,
  date_cloture     DATETIME     NULL,
  id_declarant     INT UNSIGNED NOT NULL,            -- déclare (1,1)
  id_assigne       INT UNSIGNED NULL,                -- est assigné (0,1)
  id_categorie     SMALLINT UNSIGNED NOT NULL,       -- classe (1,1)
  id_salle         SMALLINT UNSIGNED NOT NULL,       -- situe (1,1)
  id_equipement    INT UNSIGNED NULL,                -- concerne (0,1)
  PRIMARY KEY (id_ticket),
  KEY idx_ticket_file    (statut, priorite, date_creation),   -- file des techniciens (US04)
  KEY idx_ticket_declarant (id_declarant, date_creation),     -- « Mes tickets » (US02)
  KEY idx_ticket_assigne (id_assigne),
  KEY idx_ticket_categorie (id_categorie),
  KEY idx_ticket_salle   (id_salle),
  KEY idx_ticket_equipement (id_equipement),
  CONSTRAINT ck_ticket_dates CHECK (
    (date_resolution IS NULL OR date_resolution >= date_creation) AND
    (date_cloture    IS NULL OR date_cloture    >= date_creation)
  ),
  CONSTRAINT fk_ticket_declarant  FOREIGN KEY (id_declarant)  REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_assigne    FOREIGN KEY (id_assigne)    REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_categorie  FOREIGN KEY (id_categorie)  REFERENCES categorie (id_categorie)     ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_salle      FOREIGN KEY (id_salle)      REFERENCES salle (id_salle)             ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_equipement FOREIGN KEY (id_equipement) REFERENCES equipement (id_equipement)   ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE commentaire (
  id_commentaire  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contenu         TEXT NOT NULL,
  visibilite      ENUM('public','interne') NOT NULL DEFAULT 'public',
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  id_ticket       INT UNSIGNED NOT NULL,     -- porte (1,1)
  id_auteur       INT UNSIGNED NOT NULL,     -- rédige (1,1)
  PRIMARY KEY (id_commentaire),
  KEY idx_commentaire_ticket (id_ticket, date_creation),
  KEY idx_commentaire_auteur (id_auteur),
  CONSTRAINT fk_commentaire_ticket FOREIGN KEY (id_ticket) REFERENCES ticket (id_ticket)           ON DELETE RESTRICT,
  CONSTRAINT fk_commentaire_auteur FOREIGN KEY (id_auteur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE historique_statut (
  id_histo         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ancien_statut    ENUM('nouveau','assigne','en_cours','en_attente','resolu','cloture','rejete','annule') NULL,  -- NULL = création du ticket
  nouveau_statut   ENUM('nouveau','assigne','en_cours','en_attente','resolu','cloture','rejete','annule') NOT NULL,
  date_changement  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  motif            VARCHAR(255) NULL,          -- obligatoire pour « rejete » (règle 3.1)
  id_ticket        INT UNSIGNED NOT NULL,      -- possède (1,1)
  id_auteur        INT UNSIGNED NULL,          -- NULL = action du système (clôture auto après 5 jours ouvrés)
  PRIMARY KEY (id_histo),
  KEY idx_histo_ticket (id_ticket, date_changement),   -- calcul des délais de résolution
  KEY idx_histo_auteur (id_auteur),
  CONSTRAINT ck_histo_rejet CHECK (nouveau_statut <> 'rejete' OR motif IS NOT NULL),
  CONSTRAINT fk_histo_ticket FOREIGN KEY (id_ticket) REFERENCES ticket (id_ticket)           ON DELETE RESTRICT,
  CONSTRAINT fk_histo_auteur FOREIGN KEY (id_auteur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE piece_jointe (
  id_piece     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom_fichier  VARCHAR(255) NOT NULL,                       -- nom d'origine, affichage seulement
  type_mime    ENUM('image/jpeg','image/png') NOT NULL,     -- liste blanche (ENF-30)
  taille       INT UNSIGNED NOT NULL,
  chemin       VARCHAR(255) NOT NULL,                       -- nom aléatoire, hors racine web
  date_depot   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  id_ticket    INT UNSIGNED NOT NULL,     -- contient (1,1)
  id_auteur    INT UNSIGNED NOT NULL,     -- dépose (1,1)
  PRIMARY KEY (id_piece),
  UNIQUE KEY uq_piece_chemin (chemin),
  KEY idx_piece_ticket (id_ticket),
  KEY idx_piece_auteur (id_auteur),
  CONSTRAINT ck_piece_taille CHECK (taille > 0 AND taille <= 5242880),   -- 5 Mo max
  CONSTRAINT fk_piece_ticket FOREIGN KEY (id_ticket) REFERENCES ticket (id_ticket)           ON DELETE RESTRICT,
  CONSTRAINT fk_piece_auteur FOREIGN KEY (id_auteur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE notification (
  id_notification  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  canal            ENUM('email','in_app') NOT NULL,
  lue              BOOLEAN  NOT NULL DEFAULT FALSE,
  date_envoi       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  id_destinataire  INT UNSIGNED NOT NULL,   -- reçoit (1,1)
  id_ticket        INT UNSIGNED NULL,       -- notifie (0,1)
  PRIMARY KEY (id_notification),
  KEY idx_notif_non_lues (id_destinataire, lue, date_envoi),   -- cloche 🔔 du bandeau
  KEY idx_notif_ticket (id_ticket),
  CONSTRAINT fk_notif_destinataire FOREIGN KEY (id_destinataire) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT,
  CONSTRAINT fk_notif_ticket       FOREIGN KEY (id_ticket)       REFERENCES ticket (id_ticket)           ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE journal_audit (
  id_log       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  date_action  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),   -- précision à la milliseconde
  action       VARCHAR(50)  NOT NULL,          -- ex. LOGIN_FAILED, USER_DISABLED, ROLE_CHANGED
  ip           VARCHAR(45)  NOT NULL,          -- 45 = longueur max d'une adresse IPv6
  cible_type   VARCHAR(50)  NULL,              -- ex. 'utilisateur', 'ticket'
  cible_id     INT UNSIGNED NULL,
  detail       VARCHAR(255) NULL,              -- précision utile (ex. ancien > nouveau rôle) ; jamais de secret ni de code (S6)
  id_acteur    INT UNSIGNED NULL,              -- trace (0,1) : NULL si identifiant inconnu
  PRIMARY KEY (id_log),
  KEY idx_audit_date (date_action),
  KEY idx_audit_acteur (id_acteur, date_action),
  KEY idx_audit_action (action, date_action),
  CONSTRAINT fk_audit_acteur FOREIGN KEY (id_acteur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Données initiales : catégories (cahier des charges §4.1)
-- ---------------------------------------------------------------------
INSERT INTO categorie (libelle_fr, libelle_en) VALUES
  ('Matériel informatique',  'IT hardware'),
  ('Logiciel / Accès',       'Software / Access'),
  ('Réseau',                 'Network'),
  ('Infrastructures',        'Facilities'),
  ('Audiovisuel',            'Audiovisual'),
  ('Demande d''équipement',  'Equipment request');

-- =====================================================================
--  Comptes SQL — moindre privilège (R1 §4, ENF-16)
--  À exécuter en root à l'installation. Les mots de passe sont saisis
--  au moment de l'exécution et rangés ensuite dans .env.local (hors Git).
-- =====================================================================
--  1) tt_app : compte utilisé par Symfony au quotidien
--     - SELECT / INSERT / UPDATE sur les tables métier
--     - historique_statut et journal_audit : ajout seul (SELECT, INSERT)
--     - jamais de DELETE, DROP, ALTER, GRANT ni FILE
--  2) tt_migrate : compte utilisé UNIQUEMENT pendant un déploiement
--     (migrations Doctrine), absent de la configuration de l'application
-- ---------------------------------------------------------------------
-- CREATE USER 'tt_app'@'localhost'     IDENTIFIED BY '<saisi à l''installation>';
-- CREATE USER 'tt_migrate'@'localhost' IDENTIFIED BY '<saisi à l''installation>';

-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.categorie    TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.salle        TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.equipement   TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.utilisateur  TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.ticket       TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.commentaire  TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.piece_jointe TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.notification TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.jeton_compte TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON tickettournelle.code_secours TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT         ON tickettournelle.historique_statut TO 'tt_app'@'localhost';
-- GRANT SELECT, INSERT         ON tickettournelle.journal_audit     TO 'tt_app'@'localhost';

-- GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
--   ON tickettournelle.* TO 'tt_migrate'@'localhost';
