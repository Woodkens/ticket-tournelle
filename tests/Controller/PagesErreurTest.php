<?php

namespace App\Tests\Controller;

use App\Tests\Support\BaseDeTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pages d'erreur personnalisées : aucune information technique (ENF-29, mode debug coupé).
 * Le noyau est démarré avec debug = false, comme en production.
 */
final class PagesErreurTest extends WebTestCase
{
    use BaseDeTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient(['debug' => false], [
            'HTTPS' => 'on',
            'HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9',
        ]);
        $this->viderLaBase();
    }

    public function testPage404(): void
    {
        $page = $this->client->request('GET', '/une/page/qui/n-existe-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        $this->assertAucuneInformationTechnique($page);
    }

    public function testPage404EnAnglais(): void
    {
        $this->client->request('GET', '/nothing/here', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'Page not found');
    }

    public function testPage403(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur('camille.durand@latournelle.example', $motDePasse);
        $formulaire = $this->client->request('GET', '/fr/login')->filter('form[action$="/login"]')->form([
            '_username' => 'camille.durand@latournelle.example',
            '_password' => $motDePasse,
        ]);
        $this->client->submit($formulaire);

        // Personne connectée + déconnexion forgée sans jeton CSRF : refusée avec la page 403
        $page = $this->client->request('POST', '/logout');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Accès refusé');
        $this->assertAucuneInformationTechnique($page);
    }

    public function testAutreErreurAfficheLaPageGenerique(): void
    {
        // Méthode non autorisée (405) : gabarit générique, celui de l'erreur 500
        $page = $this->client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(405);
        self::assertSelectorTextContains('h1', 'Une erreur est survenue');
        $this->assertAucuneInformationTechnique($page);
    }

    /** Le texte visible ne contient ni message d'exception, ni chemin de fichier, ni nom d'outil. */
    private function assertAucuneInformationTechnique(Crawler $page): void
    {
        $texte = $page->filter('body')->text();

        foreach (['Exception', 'Symfony', 'Stack', 'Trace', 'vendor', '.php', 'Doctrine', 'SQLSTATE', 'No route', 'CSRF'] as $indice) {
            self::assertStringNotContainsStringIgnoringCase($indice, $texte);
        }
    }
}
