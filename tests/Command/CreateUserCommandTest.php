<?php

namespace App\Tests\Command;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Tests\Support\BaseDeTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Commande app:create-user : mot de passe saisi en caché, politique ENF-02,
 * et compte administrateur conforme à C5 (adresse dédiée, 15 caractères, enrôlement TOTP à venir).
 */
final class CreateUserCommandTest extends KernelTestCase
{
    use BaseDeTestTrait;

    private const EMAIL = 'premier.compte@latournelle.example';
    private const EMAIL_ADMIN = 'premier.compte+admin@latournelle.example';
    private const ARGUMENTS = ['email' => self::EMAIL, 'nom' => 'Compte', 'prenom' => 'Premier'];
    private const ARGUMENTS_ADMIN = ['email' => self::EMAIL_ADMIN, 'nom' => 'Compte', 'prenom' => 'Premier', '--role' => 'ROLE_ADMIN'];

    private CommandTester $commande;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->viderLaBase();
        $this->commande = new CommandTester((new Application(self::$kernel))->find('app:create-user'));
    }

    public function testCreationDUnCompte(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->commande->setInputs([$motDePasse, $motDePasse]);

        $code = $this->commande->execute(self::ARGUMENTS);

        self::assertSame(0, $code);
        // Le mot de passe n'est jamais réaffiché
        self::assertStringNotContainsString($motDePasse, $this->commande->getDisplay());

        $utilisateur = $this->compte();
        self::assertNotNull($utilisateur);
        self::assertSame(['ROLE_USER'], $utilisateur->getRoles());
        self::assertTrue($utilisateur->isActif());
        self::assertFalse($utilisateur->doitChangerMdp());
        // ENF-01 : seule l'empreinte Argon2id est en base
        self::assertStringStartsWith('$argon2id$', $utilisateur->getPassword());

        $journal = $this->journal();
        self::assertSame('USER_CREATED', $journal[0]['action']);
        self::assertSame('utilisateur', $journal[0]['cible_type']);
        self::assertEquals($utilisateur->getId(), $journal[0]['cible_id']);
        self::assertSame('ROLE_USER (console)', $journal[0]['detail']);
    }

    public function testCreationDUnAdministrateur(): void
    {
        $motDePasse = $this->motDePasseAleatoire(); // 29 caractères
        $this->commande->setInputs([$motDePasse, $motDePasse]);

        self::assertSame(0, $this->commande->execute(self::ARGUMENTS_ADMIN));

        $admin = $this->compte(self::EMAIL_ADMIN);
        self::assertNotNull($admin);
        self::assertTrue($admin->estAdmin());
        // Pas encore de TOTP : l'enrôlement sera forcé à la première connexion
        self::assertFalse($admin->isTotpActif());
        self::assertTrue($admin->doitEnrolerTotp());
        self::assertStringContainsString('double authentification', $this->normaliser($this->commande->getDisplay()));
    }

    public function testUnAdministrateurDoitAvoirUneAdresseDediee(): void
    {
        $code = $this->commande->execute(self::ARGUMENTS + ['--role' => 'ROLE_ADMIN']);

        self::assertSame(1, $code);
        self::assertStringContainsString('adresse dédiée', $this->normaliser($this->commande->getDisplay()));
        self::assertNull($this->compte());
    }

    public function testUneAdresseDAdministrateurEstRefuseePourUnAutreRole(): void
    {
        $code = $this->commande->execute(['--role' => 'ROLE_TECHNICIEN'] + self::ARGUMENTS_ADMIN);

        self::assertSame(1, $code);
        self::assertNull($this->compte(self::EMAIL_ADMIN));
    }

    public function testUnAdministrateurDoitChoisirQuinzeCaracteres(): void
    {
        $this->commande->setInputs(['Tq!9-xbWzr4kPm', 'Tq!9-xbWzr4kPm']); // 14 caractères

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS_ADMIN));
        self::assertStringContainsString('au moins 15 caractères', $this->normaliser($this->commande->getDisplay()));
        self::assertNull($this->compte(self::EMAIL_ADMIN));
    }

    public function testMotDePasseTropCourtRefuse(): void
    {
        $this->commande->setInputs(['Tq!9-xbWzr4', 'Tq!9-xbWzr4']); // 11 caractères

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS));
        self::assertStringContainsString('au moins 12 caractères', $this->normaliser($this->commande->getDisplay()));
        self::assertNull($this->compte());
    }

    public function testMotDePasseTropSimpleRefuse(): void
    {
        $this->commande->setInputs(['aaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaa']);

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS));
        self::assertNull($this->compte());
    }

    public function testMotDePasseCourantRefuse(): void
    {
        // Présent dans la liste locale config/securite/mots-de-passe-courants.txt
        $this->commande->setInputs(['motdepasse123', 'motdepasse123']);

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS));
        self::assertStringContainsString('les plus courants', $this->normaliser($this->commande->getDisplay()));
        self::assertNull($this->compte());
    }

    public function testMotDePasseReprenantLIdentifiantRefuse(): void
    {
        $this->commande->setInputs(['Premier.Compte-2026!', 'Premier.Compte-2026!']);

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS));
        self::assertNull($this->compte());
    }

    public function testConfirmationDifferenteRefusee(): void
    {
        $this->commande->setInputs([$this->motDePasseAleatoire(), $this->motDePasseAleatoire()]);

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS));
        self::assertNull($this->compte());
    }

    public function testEmailDejaUtiliseRefuse(): void
    {
        $this->creerUtilisateur(self::EMAIL, $this->motDePasseAleatoire());

        self::assertSame(1, $this->commande->execute(self::ARGUMENTS));
        self::assertStringContainsString('existe déjà', $this->normaliser($this->commande->getDisplay()));
    }

    public function testRefuseHorsTerminalInteractif(): void
    {
        // Sans terminal, la saisie cachée est impossible : la commande s'arrête avant de demander quoi que ce soit
        $code = $this->commande->execute(self::ARGUMENTS, ['interactive' => false]);

        self::assertSame(1, $code);
        self::assertNull($this->compte());
    }

    private function compte(string $email = self::EMAIL): ?Utilisateur
    {
        return static::getContainer()->get(UtilisateurRepository::class)->findOneByEmail($email);
    }

    /** Les messages de la console sont coupés sur plusieurs lignes : on les remet sur une seule. */
    private function normaliser(string $affichage): string
    {
        return (string) preg_replace('/\s+/', ' ', $affichage);
    }
}
