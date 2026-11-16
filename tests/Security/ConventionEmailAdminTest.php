<?php

namespace App\Tests\Security;

use App\Enum\Role;
use App\Security\Compte\ConventionEmailAdmin;
use PHPUnit\Framework\TestCase;

/**
 * C5, D-19 : l'adresse d'un compte administrateur suit une convention PARAMÉTRABLE.
 */
final class ConventionEmailAdminTest extends TestCase
{
    public function testSousAdressageParDefaut(): void
    {
        $convention = new ConventionEmailAdmin('/\+admin@/', 'prenom.nom+admin@exemple.fr');

        self::assertTrue($convention->estUneAdresseAdmin('Camille.Durand+admin@lycee.example'));
        self::assertFalse($convention->estUneAdresseAdmin('camille.durand@lycee.example'));

        self::assertTrue($convention->estCoherente('camille.durand+admin@lycee.example', Role::Admin));
        self::assertFalse($convention->estCoherente('camille.durand@lycee.example', Role::Admin));
        self::assertFalse($convention->estCoherente('camille.durand+admin@lycee.example', Role::Technicien));
        self::assertTrue($convention->estCoherente('camille.durand@lycee.example', Role::User));
    }

    public function testLaConventionPeutPasserAUnAliasSansToucherAuCode(): void
    {
        // Si la messagerie du lycée refuse le sous-adressage : alias adm-prenom.nom@ (réglage de services.yaml)
        $convention = new ConventionEmailAdmin('/^adm-/', 'adm-prenom.nom@exemple.fr');

        self::assertTrue($convention->estUneAdresseAdmin('adm-camille.durand@lycee.example'));
        self::assertFalse($convention->estUneAdresseAdmin('camille.durand+admin@lycee.example'));
        self::assertSame('adm-prenom.nom@exemple.fr', $convention->exemple());
    }
}
