<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testRacineRedirigeVersLeFrancaisPourUneLangueNonGeree(): void
    {
        // Le client de test envoie « en-us » par défaut : on force une langue non gérée.
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertResponseRedirects('/fr');
    }

    public function testRacineRespecteLaLangueAnglaiseDuNavigateur(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        self::assertResponseRedirects('/en');
    }

    public function testAccueilEnFrancais(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue sur Ticket\'Tournelle');
        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testAccueilEnAnglais(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Ticket\'Tournelle');
        self::assertSelectorExists('html[lang="en"]');
    }

    public function testLangueNonPriseEnChargeDonne404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de');

        self::assertResponseStatusCodeSame(404);
    }
}
