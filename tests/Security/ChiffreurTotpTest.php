<?php

namespace App\Tests\Security;

use App\Security\Totp\ChiffreurTotp;
use PHPUnit\Framework\TestCase;

/**
 * ENF-05, D-21 : le secret TOTP est chiffré avant d'être écrit en base.
 */
final class ChiffreurTotpTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function testLeSecretChiffreNEstPasLisibleEtSeDechiffre(): void
    {
        $chiffreur = new ChiffreurTotp($this->cle());

        $chiffre = $chiffreur->chiffrer(self::SECRET);

        self::assertStringNotContainsString(self::SECRET, $chiffre);
        self::assertStringNotContainsString(self::SECRET, (string) base64_decode($chiffre, true));
        self::assertLessThanOrEqual(255, \strlen($chiffre), 'Doit tenir dans la colonne totp_secret VARCHAR(255).');
        self::assertSame(self::SECRET, $chiffreur->dechiffrer($chiffre));
    }

    public function testDeuxChiffrementsDuMemeSecretSontDifferents(): void
    {
        $chiffreur = new ChiffreurTotp($this->cle());

        self::assertNotSame($chiffreur->chiffrer(self::SECRET), $chiffreur->chiffrer(self::SECRET));
    }

    public function testUneAutreCleNeDechiffrePas(): void
    {
        $chiffre = (new ChiffreurTotp($this->cle()))->chiffrer(self::SECRET);

        $this->expectException(\RuntimeException::class);
        (new ChiffreurTotp($this->cle()))->dechiffrer($chiffre);
    }

    public function testUneDonneeModifieeEnBaseEstDetectee(): void
    {
        $chiffreur = new ChiffreurTotp($this->cle());
        $brut = (string) base64_decode($chiffreur->chiffrer(self::SECRET), true);
        $brut[\strlen($brut) - 1] = \chr(\ord($brut[\strlen($brut) - 1]) ^ 1); // un seul bit changé

        $this->expectException(\RuntimeException::class);
        $chiffreur->dechiffrer(base64_encode($brut));
    }

    public function testCleAbsenteOuTropCourteRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ChiffreurTotp(''))->chiffrer(self::SECRET);
    }

    /** Clé aléatoire différente à chaque appel. */
    private function cle(): string
    {
        return base64_encode(random_bytes(32));
    }
}
