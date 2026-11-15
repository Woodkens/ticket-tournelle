<?php

namespace App\Tests\Controller;

use App\Tests\Support\FonctionnelTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Connexion et déconnexion (US09, ENF-03, ENF-06 à ENF-09, ENF-14).
 */
final class ConnexionTest extends FonctionnelTestCase
{
    private const EMAIL = 'camille.durand@latournelle.example';

    public function testUnePageProtegeeRedirigeVersLaConnexion(): void
    {
        $this->client->request('GET', '/fr');
        self::assertResponseRedirects('/fr/login');

        $this->client->request('GET', '/en');
        self::assertResponseRedirects('/en/login');
    }

    public function testLoginSansLangueRedirigeVersLEcranDeConnexion(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/fr/login');
    }

    public function testConnexionReussie(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $utilisateur = $this->creerUtilisateur(self::EMAIL, $motDePasse);

        $this->client->request('GET', '/fr/login');
        $sessionAvant = $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();

        $page = $this->seConnecter(self::EMAIL, $motDePasse);
        // « / » renvoie vers l'accueil dans la langue du profil
        self::assertResponseRedirects('/fr');
        $page = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Camille Durand', $page->filter('header')->text());

        // ENF-07 : l'identifiant de session a changé à la connexion
        $cookie = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($sessionAvant);
        self::assertNotNull($cookie);
        self::assertNotSame($sessionAvant, $cookie->getValue());

        // ENF-06 : cookie Secure, HttpOnly, SameSite=Lax
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());

        // ENF-14/15 : la connexion est tracée avec le compte et l'adresse IP
        $journal = $this->journal();
        self::assertCount(1, $journal);
        self::assertSame('LOGIN_SUCCESS', $journal[0]['action']);
        self::assertSame('127.0.0.1', $journal[0]['ip']);
        self::assertEquals($utilisateur->getId(), $journal[0]['id_acteur']);
    }

    public function testMauvaisMotDePasse(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $mauvais = $this->motDePasseAleatoire();
        $utilisateur = $this->creerUtilisateur(self::EMAIL, $motDePasse);

        $page = $this->seConnecter(self::EMAIL, $mauvais);

        self::assertSame('/fr/login', $this->client->getRequest()->getPathInfo());
        self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());
        $this->assertNonConnecte();

        $journal = $this->journal();
        self::assertSame('LOGIN_FAILED', $journal[0]['action']);
        self::assertEquals($utilisateur->getId(), $journal[0]['id_acteur']);

        // ENF-17 : le mot de passe saisi n'apparaît nulle part dans le journal
        self::assertStringNotContainsString($mauvais, json_encode($journal, \JSON_THROW_ON_ERROR));
    }

    public function testEmailInconnuDonneExactementLeMemeMessage(): void
    {
        $page = $this->seConnecter('inconnu@latournelle.example', $this->motDePasseAleatoire());

        self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());

        // Identifiant inconnu : pas d'acteur, et l'e-mail saisi n'est pas enregistré
        $journal = $this->journal();
        self::assertSame('LOGIN_FAILED', $journal[0]['action']);
        self::assertNull($journal[0]['id_acteur']);
        self::assertStringNotContainsString('inconnu@', json_encode($journal, \JSON_THROW_ON_ERROR));
    }

    public function testCompteDesactiveRefuseAvecLeMessageGenerique(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse, actif: false);

        // Le BON mot de passe ne suffit pas, et l'écran ne dit pas que le compte est désactivé
        $page = $this->seConnecter(self::EMAIL, $motDePasse);

        self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());
        $this->assertNonConnecte();
        self::assertSame(['LOGIN_DISABLED'], $this->actionsDuJournal());
    }

    public function testConnexionSansJetonCsrfRefusee(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);

        // Formulaire forgé par un autre site : bon mot de passe, mais pas de jeton
        $this->client->request('POST', '/fr/login', ['_username' => self::EMAIL, '_password' => $motDePasse]);
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Le formulaire a expiré', $page->filter('.alert-danger')->text());
        $this->assertNonConnecte();
        self::assertSame(['LOGIN_CSRF_INVALID'], $this->actionsDuJournal());
    }

    public function testConnexionAvecJetonCsrfInvalideRefusee(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);

        $this->client->request('POST', '/fr/login', [
            '_username' => self::EMAIL,
            '_password' => $motDePasse,
            '_csrf_token' => 'jeton-invente',
        ]);
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Le formulaire a expiré', $page->filter('.alert-danger')->text());
        $this->assertNonConnecte();
        self::assertSame(['LOGIN_CSRF_INVALID'], $this->actionsDuJournal());
    }

    public function testBlocageApresCinqEchecs(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);

        // 5 essais erronés : message générique à chaque fois
        for ($essai = 1; $essai <= 5; ++$essai) {
            $page = $this->seConnecter(self::EMAIL, $this->motDePasseAleatoire());
            self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());
        }

        // 6e essai avec le BON mot de passe : refusé quand même, la connexion est bloquée (ENF-03)
        $page = $this->seConnecter(self::EMAIL, $motDePasse);

        self::assertStringContainsString('Trop de tentatives', $page->filter('.alert-danger')->text());
        $this->assertNonConnecte();
        self::assertSame(
            ['LOGIN_FAILED', 'LOGIN_FAILED', 'LOGIN_FAILED', 'LOGIN_FAILED', 'LOGIN_FAILED', 'LOGIN_BLOCKED'],
            $this->actionsDuJournal(),
        );
    }

    public function testDeconnexion(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $utilisateur = $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->seConnecter(self::EMAIL, $motDePasse);
        $page = $this->client->followRedirect();
        $sessionConnectee = $this->client->getCookieJar()->get('MOCKSESSID')->getValue();

        // Le bouton de l'en-tête envoie un POST avec son jeton CSRF
        $this->client->submit($page->filter('form[action="/logout"]')->form());

        self::assertResponseRedirects('/fr/login');
        $this->assertNonConnecte();

        $journal = $this->journal();
        self::assertSame(['LOGIN_SUCCESS', 'LOGOUT'], array_column($journal, 'action'));
        self::assertEquals($utilisateur->getId(), $journal[1]['id_acteur']);

        // ENF-09 : la session est détruite côté serveur, l'ancien cookie ne sert plus à rien
        $this->client->getCookieJar()->set(new Cookie('MOCKSESSID', $sessionConnectee, null, '/', 'localhost', true));
        $this->assertNonConnecte();
    }

    public function testDeconnexionEnGetRefusee(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->seConnecter(self::EMAIL, $motDePasse);

        $this->client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);

        // Toujours connecté
        $this->client->request('GET', '/fr');
        self::assertResponseIsSuccessful();
    }

    public function testDeconnexionSansJetonCsrfRefusee(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->seConnecter(self::EMAIL, $motDePasse);

        $this->client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/fr');
        self::assertResponseIsSuccessful();
        self::assertSame(['LOGIN_SUCCESS'], $this->actionsDuJournal());
    }

    public function testCompteDesactivePendantLaSessionEstDeconnecte(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->seConnecter(self::EMAIL, $motDePasse);

        // Un administrateur désactive le compte alors que la personne est connectée
        $this->connexionSql()->executeStatement('UPDATE utilisateur SET actif = 0 WHERE email = ?', [self::EMAIL]);

        $this->assertNonConnecte();
    }

    public function testEcranDeConnexionEnAnglais(): void
    {
        $page = $this->seConnecter('unknown@latournelle.example', $this->motDePasseAleatoire(), 'en');

        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'Log in');
        self::assertSame('Incorrect e-mail address or password.', $page->filter('.alert-danger')->text());
    }

    public function testConnexionDansLaLangueDuProfil(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->connexionSql()->executeStatement("UPDATE utilisateur SET langue = 'en' WHERE email = ?", [self::EMAIL]);

        $this->seConnecter(self::EMAIL, $motDePasse);

        self::assertResponseRedirects('/en');
    }
}
