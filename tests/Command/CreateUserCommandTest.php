<?php

namespace App\Tests\Command;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Tests\Support\BaseDeTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Commande app:create-user : mot de passe saisi en caché, politique ENF-02, pas d'admin avant la S6.
 */
final class CreateUserCommandTest extends KernelTestCase
{
    use BaseDeTestTrait;

    private const EMAIL = 'premier.compte@latournelle.example';
    private const ARGUMENTS = ['email' => self::EMAIL, 'nom' => 'Compte', 'prenom' => 'Premier'];

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
    }

    public function testRoleAdministrateurRefuseJusquALaS6(): void
    {
        $code = $this->commande->execute(self::ARGUMENTS + ['--role' => 'ROLE_ADMIN']);

        self::assertSame(1, $code);
        self::assertStringContainsString('TOTP', $this->commande->getDisplay());
        self::assertNull($this->compte());
    }

    public function testMotDePasseTropCourtRefuse(): void
    {
        $this->commande->setInputs(['Tt!9-abcdef', 'Tt!9-abcdef']); // 11 caractères

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

    private function compte(): ?Utilisateur
    {
        return static::getContainer()->get(UtilisateurRepository::class)->findOneByEmail(self::EMAIL);
    }

    /** Les messages de la console sont coupés sur plusieurs lignes : on les remet sur une seule. */
    private function normaliser(string $affichage): string
    {
        return (string) preg_replace('/\s+/', ' ', $affichage);
    }
}
