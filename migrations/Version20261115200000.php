<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S6 — première migration du projet. Elle reproduit à l'identique les ajouts faits dans le MPD
 * (docs/ticket_tournelle_mpd.sql, source de vérité, D-06 et D-22) :
 *  - jeton_compte : liens d'activation et de réinitialisation, jeton stocké haché (ENF-04) ;
 *  - code_secours : codes de secours de la double authentification, stockés hachés (ENF-05) ;
 *  - utilisateur.totp_dernier_creneau : un code TOTP ne peut servir qu'une fois (anti-rejeu) ;
 *  - journal_audit.detail : précision d'une action (ex. ancien > nouveau rôle).
 *
 * À lancer avec le compte tt_migrate, jamais avec celui de l'application (voir README).
 * Les droits de tt_app sur les deux nouvelles tables sont donnés par deploy/dev/grant-app.sh.
 */
final class Version20261115200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S6 : jeton_compte, code_secours, utilisateur.totp_dernier_creneau, journal_audit.detail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD totp_dernier_creneau INT UNSIGNED NULL AFTER totp_actif');

        $this->addSql('ALTER TABLE journal_audit ADD detail VARCHAR(255) NULL AFTER cible_id');

        $this->addSql(<<<'SQL'
            CREATE TABLE jeton_compte (
              id_jeton          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              type              ENUM('activation','reinitialisation') NOT NULL,
              jeton_hash        CHAR(64)     NOT NULL,
              date_creation     DATETIME     NOT NULL,
              date_expiration   DATETIME     NOT NULL,
              date_utilisation  DATETIME     NULL,
              id_utilisateur    INT UNSIGNED NOT NULL,
              PRIMARY KEY (id_jeton),
              UNIQUE KEY uq_jeton_hash (jeton_hash),
              KEY idx_jeton_utilisateur (id_utilisateur, type),
              CONSTRAINT fk_jeton_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
            ) ENGINE=InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE code_secours (
              id_code           INT UNSIGNED NOT NULL AUTO_INCREMENT,
              code_hash         VARCHAR(255) NOT NULL,
              date_creation     DATETIME     NOT NULL,
              date_utilisation  DATETIME     NULL,
              id_utilisateur    INT UNSIGNED NOT NULL,
              PRIMARY KEY (id_code),
              KEY idx_code_utilisateur (id_utilisateur, date_utilisation),
              CONSTRAINT fk_code_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id_utilisateur) ON DELETE RESTRICT
            ) ENGINE=InnoDB
            SQL);
    }

    /**
     * Pas de retour arrière automatique : il faudrait SUPPRIMER des tables et des colonnes,
     * donc des données (jetons, codes, détails du journal). Le projet n'efface rien (CdC §2.1).
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La migration S6 ne se défait pas : elle supprimerait des données.');
    }

    /** MariaDB valide chaque CREATE/ALTER immédiatement : une transaction autour n'aurait aucun effet. */
    public function isTransactional(): bool
    {
        return false;
    }
}
