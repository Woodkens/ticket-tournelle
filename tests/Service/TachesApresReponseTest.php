<?php

namespace App\Tests\Service;

use App\Service\TachesApresReponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * D-24 : hors mode debug, le travail attend que la réponse soit partie (temps de réponse identique
 * que le compte existe ou non). En mode debug, il est fait tout de suite (e-mail visible dans le profiler).
 */
final class TachesApresReponseTest extends TestCase
{
    public function testHorsDebugLaTacheAttendLaFinDeLaReponse(): void
    {
        $taches = new TachesApresReponse(false, new NullLogger());
        $faite = false;

        $taches->planifier(function () use (&$faite): void { $faite = true; });
        self::assertFalse($faite, 'Rien ne doit se passer avant l\'envoi de la réponse.');

        $taches->executer(); // événement kernel.terminate
        self::assertTrue($faite);
    }

    public function testEnDebugLaTacheEstFaiteToutDeSuite(): void
    {
        $taches = new TachesApresReponse(true, new NullLogger());
        $faite = false;

        $taches->planifier(function () use (&$faite): void { $faite = true; });

        self::assertTrue($faite);
    }

    public function testUneTacheEnEchecNEmpechePasLesSuivantes(): void
    {
        $taches = new TachesApresReponse(false, new NullLogger());
        $faite = false;

        $taches->planifier(static function (): void { throw new \RuntimeException('panne du serveur de messagerie'); });
        $taches->planifier(function () use (&$faite): void { $faite = true; });
        $taches->executer();

        self::assertTrue($faite);
    }
}
