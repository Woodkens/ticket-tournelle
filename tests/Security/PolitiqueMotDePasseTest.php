<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use App\Enum\Role;
use App\Security\Compte\PolitiqueMotDePasse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ENF-02, D-23 : longueur, informations personnelles, liste LOCALE de mots de passe courants, solidité.
 */
final class PolitiqueMotDePasseTest extends KernelTestCase
{
    public function testMotDePasseSolideAccepte(): void
    {
        self::assertSame([], $this->refus('Brouette-Verte-42-Nuage', $this->compte()));
    }

    public function testDouzeCaracteresMinimumPourUnUtilisateur(): void
    {
        self::assertSame(['password.too_short'], $this->refus('Tq!9-xbWzr4', $this->compte())); // 11 caractères
        self::assertSame([], $this->refus('Tq!9-xbWzr4k', $this->compte()));                    // 12 caractères
    }

    public function testQuinzeCaracteresMinimumPourUnAdministrateur(): void
    {
        $admin = $this->compte(Role::Admin, 'camille.durand+admin@lycee.example');

        self::assertSame(['password.too_short'], $this->refus('Tq!9-xbWzr4kPm', $admin)); // 14 caractères
        self::assertSame([], $this->refus('Tq!9-xbWzr4kPmY', $admin));                    // 15 caractères
    }

    public function testMotDePasseDeLaListeLocaleRefuse(): void
    {
        // Présents dans config/securite/mots-de-passe-courants.txt, quelle que soit la casse
        self::assertContains('password.common', $this->refus('motdepasse123', $this->compte()));
        self::assertContains('password.common', $this->refus('MotDePasse123', $this->compte()));
        self::assertContains('password.common', $this->refus('administrateur', $this->compte()));
    }

    public function testNomPrenomIdentifiantEtMotsDuLyceeRefuses(): void
    {
        $compte = $this->compte();

        self::assertContains('password.personal', $this->refus('Xk9!Durand-Zp4#vv', $compte));
        self::assertContains('password.personal', $this->refus('Xk9!CAMILLE-Zp4#vv', $compte));
        self::assertContains('password.personal', $this->refus('Xk9!camille.durand#Zp', $compte));
        self::assertContains('password.personal', $this->refus('Xk9!Tournelle-Zp4#vv', $compte));
        self::assertContains('password.personal', $this->refus('Xk9!Lycée-Zp4#vv-qq', $compte));
    }

    public function testMotDePasseTropSimpleRefuse(): void
    {
        self::assertContains('password.too_weak', $this->refus('aaaaaaaaaaaaaaaa', $this->compte()));
    }

    /** @return list<string> clés de traduction des refus */
    private function refus(string $motDePasse, Utilisateur $compte): array
    {
        $politique = static::getContainer()->get(PolitiqueMotDePasse::class);

        return array_map(static fn ($message): string => $message->getMessage(), $politique->verifier($motDePasse, $compte));
    }

    private function compte(Role $role = Role::User, string $email = 'camille.durand@lycee.example'): Utilisateur
    {
        return new Utilisateur($email, 'Durand', 'Camille', $role);
    }
}
