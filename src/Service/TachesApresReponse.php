<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exécute un travail APRÈS l'envoi de la réponse au navigateur (événement « kernel.terminate »).
 *
 * Utilité (D-24) : pour « mot de passe oublié », créer le lien et envoyer l'e-mail prend du temps
 * seulement si le compte existe. En le faisant après la réponse, la page s'affiche aussi vite dans
 * les deux cas : le chronomètre ne révèle pas quels comptes existent (même idée que D-18).
 *
 * En mode debug (développement, tests), le travail est fait tout de suite : sinon l'e-mail
 * n'apparaîtrait pas dans le profiler Symfony.
 */
final class TachesApresReponse implements EventSubscriberInterface
{
    /** @var list<callable(): void> */
    private array $taches = [];

    public function __construct(
        private readonly bool $modeDebug,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'executer'];
    }

    /** @param callable(): void $tache */
    public function planifier(callable $tache): void
    {
        if ($this->modeDebug) {
            $tache();

            return;
        }

        $this->taches[] = $tache;
    }

    public function executer(): void
    {
        $taches = $this->taches;
        $this->taches = [];

        foreach ($taches as $tache) {
            try {
                $tache();
            } catch (\Throwable $erreur) {
                // La réponse est déjà partie : on garde une trace, sans détail sensible
                $this->logger->error('Tâche après réponse en échec : {classe}', ['classe' => $erreur::class]);
            }
        }
    }
}
