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

    /** Saisit un code (TOTP ou de secours) dans l'écran de double authentification. */
    protected function saisirCodeTotp(string $code): void
    {
        $page = $this->client->request('GET', '/2fa');
        self::assertResponseIsSuccessful();

        $this->client->submit($page->filter('form[action$="/2fa_check"]')->form(['_auth_code' => $code]));
    }

    /** Connexion complète d'un administrateur : mot de passe, puis code TOTP du moment. */
    protected function seConnecterEnAdmin(string $email, string $motDePasse, string $secretTotp): void
    {
        $this->seConnecter($email, $motDePasse);
        $this->saisirCodeTotp($this->codeTotp($secretTotp));
        self::assertResponseRedirects();
    }

    protected function assertNonConnecte(): void
    {
        $this->client->request('GET', '/fr');
        self::assertResponseRedirects('/fr/login');
    }

    /**
     * Fabrique un jeton CSRF valable pour la session du navigateur de test, afin de simuler une requête
     * écrite « à la main » par quelqu'un qui contourne les boutons de l'interface.
     */
    protected function jetonCsrf(string $identifiant): string
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/https-'.$identifiant, 'jeton-fabrique-par-le-test');
        $session->save();

        return 'jeton-fabrique-par-le-test';
    }

    /** Extrait le lien (activation ou réinitialisation) du dernier e-mail envoyé pendant la requête. */
    protected function lienDuDernierEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertNotNull($email, 'Un e-mail aurait dû être envoyé.');
        self::assertMatchesRegularExpression('#https://localhost(/(fr|en)/compte/mot-de-passe/[A-Za-z0-9_-]{43})#', (string) $email->getTextBody());
        preg_match('#https://localhost(/(fr|en)/compte/mot-de-passe/[A-Za-z0-9_-]{43})#', (string) $email->getTextBody(), $trouve);

        return $trouve[1];
    }
}
