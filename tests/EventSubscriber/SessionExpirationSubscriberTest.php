<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Utilisateur;
use App\Enum\Role;
use App\EventSubscriber\SessionExpirationSubscriber;
use App\Tests\Support\BaseDeTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * ENF-08 : 30 minutes d'inactivité (15 pour un administrateur), 8 heures au maximum.
 * On ne peut pas attendre 30 minutes dans un test : on écrit dans la session une heure passée.
 */
final class SessionExpirationSubscriberTest extends KernelTestCase
{
    use BaseDeTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->viderLaBase();
    }

    public function testSessionActiveDepuisPeuResteOuverte(): void
    {
        $evenement = $this->requeteDe(Role::User, inactifDepuis: 29 * 60, connecteDepuis: 3600);

        self::assertNull($evenement->getResponse());
        self::assertNotNull($this->jetons()->getToken());
        // L'heure de dernière activité est remise à maintenant
        $activite = $evenement->getRequest()->getSession()->get(SessionExpirationSubscriber::CLE_ACTIVITE);
        self::assertEqualsWithDelta(time(), $activite, 2);
        self::assertSame([], $this->actionsDuJournal());
    }

    public function testUtilisateurInactifDepuisPlusDe30MinutesEstDeconnecte(): void
    {
        $evenement = $this->requeteDe(Role::User, inactifDepuis: 31 * 60, connecteDepuis: 3600);

        $this->assertSessionExpiree($evenement);
    }

    public function testAdministrateurInactifDepuisPlusDe15MinutesEstDeconnecte(): void
    {
        $evenement = $this->requeteDe(Role::Admin, inactifDepuis: 16 * 60, connecteDepuis: 3600);

        $this->assertSessionExpiree($evenement);
    }

    public function testUtilisateurInactifDepuis16MinutesResteConnecte(): void
    {
        $evenement = $this->requeteDe(Role::User, inactifDepuis: 16 * 60, connecteDepuis: 3600);

        self::assertNull($evenement->getResponse());
    }

    public function testSessionDePlusDe8HeuresEstFermeeMemeActive(): void
    {
        $evenement = $this->requeteDe(Role::User, inactifDepuis: 60, connecteDepuis: 8 * 3600 + 60);

        $this->assertSessionExpiree($evenement);
    }

    /** Simule une page demandée par un compte connecté, avec une session plus ou moins ancienne. */
    private function requeteDe(Role $role, int $inactifDepuis, int $connecteDepuis): RequestEvent
    {
        $utilisateur = $this->creerUtilisateur('session@latournelle.example', $this->motDePasseAleatoire(), $role);
        $this->jetons()->setToken(new UsernamePasswordToken($utilisateur, 'main', $utilisateur->getRoles()));

        $session = new Session(new MockArraySessionStorage());
        $session->set(SessionExpirationSubscriber::CLE_DEBUT, time() - $connecteDepuis);
        $session->set(SessionExpirationSubscriber::CLE_ACTIVITE, time() - $inactifDepuis);
        $session->set('donnee_de_session', 'présente');

        $requete = Request::create('https://localhost/fr');
        $requete->setSession($session);

        $evenement = new RequestEvent(self::$kernel, $requete, HttpKernelInterface::MAIN_REQUEST);
        static::getContainer()->get(SessionExpirationSubscriber::class)->surRequete($evenement);

        return $evenement;
    }

    private function assertSessionExpiree(RequestEvent $evenement): void
    {
        $reponse = $evenement->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertStringEndsWith('/fr/login?expire=1', $reponse->getTargetUrl());

        // Plus de compte connecté, session vidée côté serveur (ENF-09), expiration tracée
        self::assertNull($this->jetons()->getToken());
        self::assertFalse($evenement->getRequest()->getSession()->has('donnee_de_session'));
        self::assertSame(['SESSION_EXPIRED'], $this->actionsDuJournal());
    }

    private function jetons(): TokenStorageInterface
    {
        return static::getContainer()->get('security.token_storage');
    }
}
