<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\TempsDeReponseConnexionSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * D-18 : un e-mail inconnu ou un compte désactivé coûte un calcul Argon2id, comme un mauvais mot de passe.
 *
 * On ne mesure pas des durées (un chronomètre dans un test donne des résultats instables, et le coût
 * d'Argon2id est réduit au minimum en test) : on compte les calculs d'empreinte déclenchés.
 */
final class TempsDeReponseConnexionSubscriberTest extends KernelTestCase
{
    public function testEmailInconnuDeclencheUnCalculFactice(): void
    {
        // Symfony enveloppe l'erreur d'origine dans une erreur générique
        $erreur = new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException());

        self::assertSame(1, $this->calculsDeclenchesPar($erreur));
    }

    public function testCompteDesactiveDeclencheUnCalculFactice(): void
    {
        $erreur = new BadCredentialsException('Bad credentials.', 0, new DisabledException());

        self::assertSame(1, $this->calculsDeclenchesPar($erreur));
    }

    public function testMauvaisMotDePasseNAjouteRien(): void
    {
        // Le mot de passe a déjà été vérifié par Symfony : un seul calcul au total, pas deux
        self::assertSame(0, $this->calculsDeclenchesPar(new BadCredentialsException('The presented password is invalid.')));
    }

    public function testBlocageEtCsrfInvalideNAjoutentRien(): void
    {
        // Réponses rapides dans tous les cas, que le compte existe ou non : rien à égaliser
        self::assertSame(0, $this->calculsDeclenchesPar(new TooManyLoginAttemptsAuthenticationException(15)));
        self::assertSame(0, $this->calculsDeclenchesPar(new InvalidCsrfTokenException()));
    }

    public function testLeMecanismeEstBrancheSurLePareFeu(): void
    {
        self::bootKernel();
        $ecouteurs = static::getContainer()->get('security.event_dispatcher.main')->getListeners(LoginFailureEvent::class);

        $classes = array_map(
            static fn (callable $ecouteur): string => \is_array($ecouteur) ? $ecouteur[0]::class : '',
            $ecouteurs,
        );

        self::assertContains(TempsDeReponseConnexionSubscriber::class, $classes);
    }

    /** Lance l'écouteur avec un faux « hasher » qui compte ses appels au lieu de calculer. */
    private function calculsDeclenchesPar(AuthenticationException $erreur): int
    {
        $compteur = new class implements PasswordHasherInterface {
            public int $calculs = 0;

            public function hash(#[\SensitiveParameter] string $plainPassword): string
            {
                ++$this->calculs;

                return 'empreinte';
            }

            public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
            {
                ++$this->calculs;

                return false;
            }

            public function needsRehash(string $hashedPassword): bool
            {
                return false;
            }
        };

        $fabrique = self::createStub(PasswordHasherFactoryInterface::class);
        $fabrique->method('getPasswordHasher')->willReturn($compteur);

        $evenement = new LoginFailureEvent(
            $erreur,
            self::createStub(AuthenticatorInterface::class),
            Request::create('https://localhost/fr/login', 'POST'),
            null,
            'main',
        );
        (new TempsDeReponseConnexionSubscriber($fabrique))->surConnexionEchouee($evenement);

        return $compteur->calculs;
    }
}
