<?php

namespace App\Tests\Architecture;

use App\Enum\TypeJeton;
use App\Security\Compte\GestionJetons;
use App\Tests\Support\FonctionnelTestCase;

/**
 * D-26 : PHP et MariaDB travaillent tous les deux en temps universel (UTC), et l'écran affiche
 * l'heure de Paris. Ce test empêche le retour du « bug d'heure » (une heure d'écart entre les dates
 * posées par la base et celles écrites par PHP).
 */
final class DatesEnUtcTest extends FonctionnelTestCase
{
    public function testPhpEtMariaDbSontALaMemeHeureEnUtc(): void
    {
        self::assertSame('UTC', date_default_timezone_get());
        self::assertSame('+00:00', $this->connexionSql()->fetchOne('SELECT @@session.time_zone'));

        // NOW() de la base et l'horloge de PHP donnent la même heure (à quelques secondes près)
        $heureDeLaBase = strtotime((string) $this->connexionSql()->fetchOne('SELECT NOW()'));
        self::assertEqualsWithDelta(time(), $heureDeLaBase, 5);
    }

    public function testUneDateEcriteParPhpEtUneDatePoseeParLaBaseConcordent(): void
    {
        $compte = $this->creerUtilisateur('camille.durand@latournelle.example', $this->motDePasseAleatoire());

        // Date écrite par PHP (création du jeton) et date calculée par MariaDB, au même instant
        static::getContainer()->get(GestionJetons::class)->creer($compte, TypeJeton::Reinitialisation);
        $ecart = (int) $this->connexionSql()->fetchOne('SELECT TIMESTAMPDIFF(SECOND, date_creation, NOW()) FROM jeton_compte');
        self::assertEqualsWithDelta(0, $ecart, 5, 'La base et PHP ne sont pas à la même heure.');

        // Le lien de 30 minutes expire bien dans 30 minutes pour la base aussi (et pas dans 1 h 30)
        $reste = (int) $this->connexionSql()->fetchOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), date_expiration) FROM jeton_compte');
        self::assertEqualsWithDelta(30 * 60, $reste, 5);

        // Date posée par la base (journal d'audit) : même heure que PHP
        $this->seConnecter('camille.durand@latournelle.example', 'mauvais-'.$this->motDePasseAleatoire());
        $dateDuJournal = strtotime((string) $this->connexionSql()->fetchOne('SELECT MAX(date_action) FROM journal_audit'));
        self::assertEqualsWithDelta(time(), $dateDuJournal, 5);
    }

    public function testLEmailAnnonceLExpirationALHeureDeParis(): void
    {
        $this->creerUtilisateur('camille.durand@latournelle.example', $this->motDePasseAleatoire());

        $page = $this->client->request('GET', '/fr/mot-de-passe-oublie');
        $this->client->submit($page->filter('form[name="mot_de_passe_oublie"]')->form([
            'mot_de_passe_oublie[email]' => 'camille.durand@latournelle.example',
        ]));
        $email = self::getMailerMessage();
        self::assertNotNull($email);

        // L'expiration est en UTC dans la base ; l'e-mail doit l'annoncer à l'heure de Paris
        $expirationUtc = new \DateTimeImmutable((string) $this->connexionSql()->fetchOne('SELECT date_expiration FROM jeton_compte'), new \DateTimeZone('UTC'));
        $heureDeParis = $expirationUtc->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');

        self::assertNotSame($expirationUtc->format('d/m/Y H:i'), $heureDeParis);
        self::assertEmailTextBodyContains($email, $heureDeParis);
        self::assertEmailHtmlBodyContains($email, $heureDeParis);
    }

    public function testLEcranAfficheLHeureDeParisEteCommeHiver(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [, $secret] = $this->creerAdminEnrole('alice.admin+admin@latournelle.example', $motDePasse);
        $hiver = $this->creerUtilisateur('hiver@latournelle.example', $this->motDePasseAleatoire(), nom: 'Hiver', prenom: 'Hugo');
        $ete = $this->creerUtilisateur('ete@latournelle.example', $this->motDePasseAleatoire(), nom: 'Ete', prenom: 'Emma');

        // Deux connexions enregistrées à 10 h 00 UTC : l'une en janvier, l'autre en juillet
        foreach ([[$hiver, '2026-01-15 10:00:00'], [$ete, '2026-07-01 10:00:00']] as [$compte, $dateUtc]) {
            $this->connexionSql()->executeStatement(
                "INSERT INTO journal_audit (date_action, action, ip, id_acteur) VALUES (?, 'LOGIN_SUCCESS', '127.0.0.1', ?)",
                [$dateUtc, $compte->getId()],
            );
        }

        $this->seConnecterEnAdmin('alice.admin+admin@latournelle.example', $motDePasse, $secret);
        $page = $this->client->request('GET', '/fr/admin/comptes');

        // Heure de Paris : UTC + 1 en hiver, UTC + 2 en été
        self::assertStringContainsString('15/01/2026 11:00', $page->filter(sprintf('tr[data-compte="%d"]', $hiver->getId()))->text());
        self::assertStringContainsString('01/07/2026 12:00', $page->filter(sprintf('tr[data-compte="%d"]', $ete->getId()))->text());
    }
}
