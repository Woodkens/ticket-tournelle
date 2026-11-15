<?php

namespace App\Tests\Architecture;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\RouterInterface;

/**
 * « JAMAIS de suppression physique, ni dans l'interface ni dans le code » (CdC §2.1).
 * Ce test relit le code et les routes de l'application : il échoue si quelqu'un ajoute une suppression.
 */
final class AucuneSuppressionTest extends KernelTestCase
{
    public function testAucuneInstructionDeSuppressionDansLeCode(): void
    {
        $interdits = [
            '->remove(' => 'suppression d\'une entité par Doctrine',
            'DELETE FROM' => 'requête SQL DELETE',
            'TRUNCATE' => 'vidage d\'une table',
            '->delete(' => 'suppression par requête',
        ];

        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2).'/src')->name('*.php') as $fichier) {
            // La session n'est pas la base : y retirer une valeur n'efface aucune donnée enregistrée
            $code = str_replace('getSession()->remove(', '', $fichier->getContents());

            foreach ($interdits as $motif => $explication) {
                self::assertStringNotContainsStringIgnoringCase(
                    $motif,
                    $code,
                    sprintf('%s contient « %s » (%s).', $fichier->getRelativePathname(), $motif, $explication),
                );
            }
        }
    }

    public function testAucuneRouteDeSuppression(): void
    {
        self::bootKernel();
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach ($routes as $nom => $route) {
            if (!str_starts_with($nom, 'app_')) {
                continue; // routes des outils Symfony (profiler…)
            }

            self::assertDoesNotMatchRegularExpression('/suppr|delete|remove|effacer/i', $nom.' '.$route->getPath());
            self::assertNotContains('DELETE', $route->getMethods(), sprintf('La route %s accepte la méthode DELETE.', $nom));
        }
    }
}
