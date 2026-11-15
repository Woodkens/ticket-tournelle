<?php

namespace App\Tests\Controller;

use App\Enum\Role;
use App\Security\Compte\GestionComptes;
use App\Tests\Support\FonctionnelTestCase;

/**
 * « Mot de passe oublié » (US10, ENF-04) : même réponse que le compte existe ou non,
 * lien à usage unique valable 30 minutes, e-mail dans la langue du profil.
 */
final class MotDePasseOublieTest extends FonctionnelTestCase
{
    private const EMAIL = 'camille.durand@latournelle.example';

    public function testLaReponseEstLaMemePourUnCompteConnuOuInconnu(): void
    {
        $this->creerUtilisateur(self::EMAIL, $this->motDePasseAleatoire());

        $this->demander(self::EMAIL);
        self::assertEmailCount(1);
        self::assertResponseRedirects('/fr/mot-de-passe-oublie/envoye');
        $pageConnu = $this->client->followRedirect()->filter('main')->text();

        $this->demander('personne@latournelle.example');
        self::assertEmailCount(0);
        self::assertResponseRedirects('/fr/mot-de-passe-oublie/envoye');
        $pageInconnu = $this->client->followRedirect()->filter('main')->text();

        self::assertSame($pageConnu, $pageInconnu);

        // Journal : compte noté s'il existe, et jamais l'adresse saisie quand elle est inconnue
        $journal = $this->journal();
        self::assertSame(['PASSWORD_RESET_REQUESTED', 'PASSWORD_RESET_REQUESTED'], array_column($journal, 'action'));
        self::assertNotNull($journal[0]['id_acteur']);
        self::assertNull($journal[1]['id_acteur']);
        self::assertStringNotContainsString('personne@', json_encode($journal, \JSON_THROW_ON_ERROR));
    }

    public function testReinitialisationComplete(): void
    {
        $ancien = $this->motDePasseAleatoire();
        $nouveau = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $ancien);

        $this->demander(self::EMAIL);
        $lien = $this->lienDuDernierEmail();

        // Le lien de réinitialisation vaut 30 minutes, et seule son empreinte est en base
        $jeton = $this->connexionSql()->fetchAssociative('SELECT * FROM jeton_compte');
        self::assertIsArray($jeton);
        self::assertSame('reinitialisation', $jeton['type']);
        self::assertSame(30 * 60, strtotime($jeton['date_expiration']) - strtotime($jeton['date_creation']));
        self::assertSame(hash('sha256', basename($lien)), $jeton['jeton_hash']);

        $page = $this->client->request('GET', $lien);
        self::assertSelectorTextContains('h1', 'Nouveau mot de passe');
        $this->client->submit($page->filter('form[name="choix_mot_de_passe"]')->form([
            'choix_mot_de_passe[motDePasse][first]' => $nouveau,
            'choix_mot_de_passe[motDePasse][second]' => $nouveau,
        ]));
        self::assertResponseRedirects('/fr/login');

        // L'ancien mot de passe ne marche plus, le nouveau oui
        $page = $this->seConnecter(self::EMAIL, $ancien);
        self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());
        $this->seConnecter(self::EMAIL, $nouveau);
        self::assertResponseRedirects('/fr');

        self::assertContains('PASSWORD_RESET_DONE', $this->actionsDuJournal());

        // Le lien a servi : il est refusé ensuite
        $this->client->request('GET', $lien);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTroisDemandesAuMaximumParQuartDHeure(): void
    {
        $this->creerUtilisateur(self::EMAIL, $this->motDePasseAleatoire());

        for ($i = 1; $i <= 3; ++$i) {
            $this->demander(self::EMAIL);
            self::assertEmailCount(1);
        }

        // 4e demande : même page, mais plus aucun e-mail ne part
        $this->demander(self::EMAIL);
        self::assertEmailCount(0);
        self::assertResponseRedirects('/fr/mot-de-passe-oublie/envoye');
        self::assertSame('limite atteinte', $this->journal()[3]['detail']);
    }

    public function testUnCompteDesactiveNeRecoitRien(): void
    {
        $this->creerUtilisateur(self::EMAIL, $this->motDePasseAleatoire(), actif: false);

        $this->demander(self::EMAIL);

        self::assertEmailCount(0);
        self::assertResponseRedirects('/fr/mot-de-passe-oublie/envoye');
    }

    public function testUnCompteJamaisActiveRecoitUnLienDActivation(): void
    {
        static::getContainer()->get(GestionComptes::class)->creer(null, self::EMAIL, 'Durand', 'Camille', Role::User);

        $this->demander(self::EMAIL);

        self::assertEmailCount(1);
        self::assertSame('activation', $this->connexionSql()->fetchOne('SELECT type FROM jeton_compte'));
    }

    public function testLEmailEstEcritDansLaLangueDuProfil(): void
    {
        $this->creerUtilisateur(self::EMAIL, $this->motDePasseAleatoire());

        $this->demander(self::EMAIL);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', 'Ticket\'Tournelle : choisir un nouveau mot de passe');
        self::assertEmailTextBodyContains($email, 'Bonjour Camille,');
        self::assertEmailHtmlBodyContains($email, '/fr/compte/mot-de-passe/');

        // Même personne, profil en anglais : sujet, texte et lien en anglais
        $this->connexionSql()->executeStatement("UPDATE utilisateur SET langue = 'en'");
        $this->demander(self::EMAIL);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', 'Ticket\'Tournelle: choose a new password');
        self::assertEmailTextBodyContains($email, 'Hello Camille,');
        self::assertEmailTextBodyContains($email, '/en/compte/mot-de-passe/');
    }

    private function demander(string $email): void
    {
        $page = $this->client->request('GET', '/fr/mot-de-passe-oublie');
        self::assertResponseIsSuccessful();

        $this->client->submit($page->filter('form[name="mot_de_passe_oublie"]')->form(['mot_de_passe_oublie[email]' => $email]));
    }
}
