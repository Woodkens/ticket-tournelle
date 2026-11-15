<?php

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base des tests fonctionnels : un « navigateur » de test qui parle à l'application en HTTPS
 * (obligatoire, car le cookie de session est marqué Secure) et en français.
 */
abstract class FonctionnelTestCase extends WebTestCase
{
    use BaseDeTestTrait;

    protected const MESSAGE_GENERIQUE = 'Identifiant ou mot de passe incorrect.';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], [
            'HTTPS' => 'on',
            'HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9',
        ]);
        $this->viderLaBase();
    }

    /** Remplit et envoie le vrai formulaire de connexion (avec son jeton CSRF), puis suit la redirection. */
    protected function seConnecter(string $email, string $motDePasse, string $locale = 'fr'): Crawler
    {
        $page = $this->client->request('GET', '/'.$locale.'/login');
        self::assertResponseIsSuccessful();

        $formulaire = $page->filter('form[action$="/login"]')->form([
            '_username' => $email,
            '_password' => $motDePasse,
        ]);
        $this->client->submit($formulaire);

        return $this->client->followRedirect();
    }

    protected function assertNonConnecte(): void
    {
        $this->client->request('GET', '/fr');
        self::assertResponseRedirects('/fr/login');
    }
}
