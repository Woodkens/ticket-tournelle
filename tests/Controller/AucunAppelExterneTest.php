<?php

namespace App\Tests\Controller;

use App\Tests\Support\FonctionnelTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * « Aucun CDN » (C3, D-17) : les pages ne chargent rien depuis un autre serveur que le nôtre.
 * On lit, dans le HTML, tout ce que le navigateur irait chercher : scripts, feuilles de style,
 * images, cadres, table d'import JavaScript, et la cible des formulaires.
 */
final class AucunAppelExterneTest extends FonctionnelTestCase
{
    public function testEcranDeConnexion(): void
    {
        $this->assertAucunAppelExterne($this->client->request('GET', '/fr/login'));
        $this->assertAucunAppelExterne($this->client->request('GET', '/en/login'));
    }

    public function testAccueilUneFoisConnecte(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur('camille.durand@latournelle.example', $motDePasse);
        $this->seConnecter('camille.durand@latournelle.example', $motDePasse);

        $this->assertAucunAppelExterne($this->client->request('GET', '/fr'));
    }

    public function testPageDErreur(): void
    {
        // Noyau sans debug : la page 404 personnalisée, comme en production
        static::ensureKernelShutdown();
        $client = static::createClient(['debug' => false], ['HTTPS' => 'on']);

        $page = $client->request('GET', '/une/page/qui/n-existe-pas');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertAucunAppelExterne($page);
    }

    private function assertAucunAppelExterne(Crawler $page): void
    {
        $html = $page->html();

        // 1) Plus aucune trace du CDN retiré
        self::assertStringNotContainsString('jspm.io', $html);
        self::assertStringNotContainsString('es-module-shims', $html);

        // 2) Tout ce qui est chargé ou visé a une adresse locale : elle commence par « / » (mais pas « // »)
        $adresses = [
            ...$page->filter('script[src]')->extract(['src']),
            ...$page->filter('link[href]')->extract(['href']),
            ...$page->filter('img[src], iframe[src], source[src], video[src], audio[src]')->extract(['src']),
            ...$page->filter('form[action]')->extract(['action']),
        ];

        // La table d'import indique au navigateur où trouver chaque module JavaScript
        $tableImport = $page->filter('script[type="importmap"]');
        self::assertCount(1, $tableImport);
        $modules = json_decode($tableImport->text(), true, flags: \JSON_THROW_ON_ERROR)['imports'];
        foreach ($modules as $adresse) {
            if (!str_starts_with($adresse, 'data:')) { // « data: » = contenu écrit dans la page, pas un téléchargement
                $adresses[] = $adresse;
            }
        }

        self::assertNotEmpty($adresses);
        foreach ($adresses as $adresse) {
            self::assertMatchesRegularExpression('#^/(?!/)#', $adresse, sprintf('Adresse non locale : %s', $adresse));
        }

        // 3) Aucun script écrit dans la page ne fabrique un appel vers l'extérieur
        foreach ($page->filter('script:not([src]):not([type="importmap"])') as $script) {
            self::assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $script->textContent);
        }
    }
}
