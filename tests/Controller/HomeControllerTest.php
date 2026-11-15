<?php

namespace App\Tests\Controller;

use App\Tests\Support\FonctionnelTestCase;

/**
 * Accueil bilingue (C2). Depuis la S5, l'accueil exige d'être connecté.
 */
final class HomeControllerTest extends FonctionnelTestCase
{
    private const EMAIL = 'camille.durand@latournelle.example';

    public function testRacineRedirigeVersLeFrancaisPourUneLangueNonGeree(): void
    {
        // Visiteur non connecté, navigateur en allemand : écran de connexion en français
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertResponseRedirects('/fr/login');
    }

    public function testRacineRespecteLaLangueAnglaiseDuNavigateur(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        self::assertResponseRedirects('/en/login');
    }

    public function testAccueilEnFrancais(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->seConnecter(self::EMAIL, $motDePasse);

        $this->client->request('GET', '/fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue sur Ticket\'Tournelle');
        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testAccueilEnAnglais(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::EMAIL, $motDePasse);
        $this->seConnecter(self::EMAIL, $motDePasse);

        $this->client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Ticket\'Tournelle');
        self::assertSelectorExists('html[lang="en"]');
        // Le sélecteur de langue garde la page courante
        self::assertSelectorExists('.tt-langues a[href="/fr"]');
    }

    public function testLangueNonPriseEnChargeDonne404(): void
    {
        $this->client->request('GET', '/de');

        self::assertResponseStatusCodeSame(404);
    }
}
