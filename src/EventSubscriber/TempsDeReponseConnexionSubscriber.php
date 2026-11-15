<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Empêche de deviner quels comptes existent « au chronomètre » (D-18).
 *
 * Le problème : Argon2id est volontairement lent (ENF-01). Pour un e-mail connu, Symfony calcule
 * une empreinte pour vérifier le mot de passe ; pour un e-mail inconnu ou un compte désactivé,
 * il s'arrête avant, donc répond nettement plus vite. Le message est le même, mais la durée trahit le compte.
 *
 * La parade : dans ces deux cas, on calcule quand même une empreinte Argon2id sur une valeur factice,
 * avec les mêmes réglages. Le résultat est jeté : seul le temps passé compte.
 */
final class TempsDeReponseConnexionSubscriber implements EventSubscriberInterface
{
    /** Valeur sans importance : ce n'est le mot de passe de personne. */
    private const VALEUR_FACTICE = 'valeur-factice-pour-egaliser-le-temps';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginFailureEvent::class => 'surConnexionEchouee'];
    }

    public function surConnexionEchouee(LoginFailureEvent $event): void
    {
        // L'écran reçoit une erreur générique ; la vraie raison est dans getPrevious()
        $erreur = $event->getException();
        $origine = $erreur->getPrevious() ?? $erreur;

        // E-mail inconnu, ou compte refusé avant la vérification du mot de passe (désactivé) :
        // aucun calcul Argon2id n'a eu lieu, on en fait un pour durer aussi longtemps.
        // Mauvais mot de passe : le calcul a déjà été fait. Blocage ou CSRF invalide : la réponse
        // est rapide que le compte existe ou non, donc elle ne révèle rien.
        if ($origine instanceof UserNotFoundException || $origine instanceof AccountStatusException) {
            $this->hasherFactory->getPasswordHasher(Utilisateur::class)->hash(self::VALEUR_FACTICE);
        }
    }
}
