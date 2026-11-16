<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enrôlement TOTP forcé (C5) : un administrateur qui n'a pas encore activé sa double authentification
 * n'a accès à RIEN d'autre que l'écran d'enrôlement (et la déconnexion).
 * Quelle que soit la page demandée, il est ramené à l'enrôlement.
 */
final class EnrolementTotpSubscriber implements EventSubscriberInterface
{
    private const ROUTES_PERMISES = ['app_2fa_enrolement', 'app_2fa_qr', 'app_logout'];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité 6 : après le pare-feu (8) et le contrôle d'expiration de session (7)
        return [KernelEvents::REQUEST => ['surRequete', 6]];
    }

    public function surRequete(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $jeton = $this->tokenStorage->getToken();
        $compte = $jeton?->getUser();

        // Compte en attente de son code TOTP : c'est scheb/2fa-bundle qui s'en occupe
        if (!$compte instanceof Utilisateur || $jeton instanceof TwoFactorTokenInterface) {
            return;
        }

        if ($compte->doitEnrolerTotp() && !\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES_PERMISES, true)) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_2fa_enrolement')));
        }
    }
}
