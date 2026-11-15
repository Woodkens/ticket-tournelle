<?php

namespace App\Security\Totp;

use App\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Limite les essais de codes (ENF-05) : 5 codes faux par compte sur 15 minutes, puis la saisie est bloquée,
 * pour les codes TOTP comme pour les codes de secours. Un code à 6 chiffres n'a qu'un million de valeurs :
 * sans limite, on le trouverait par essais successifs.
 */
final class LimiteurTotp
{
    public function __construct(
        #[Autowire(service: 'limiter.totp_echecs')]
        private readonly RateLimiterFactory $limiteur,
    ) {
    }

    public function estBloque(Utilisateur $compte): bool
    {
        // consume(0) ne consomme rien : il indique seulement combien d'essais il reste
        return $this->pour($compte)->consume(0)->getRemainingTokens() <= 0;
    }

    public function noterEchec(Utilisateur $compte): void
    {
        $this->pour($compte)->consume();
    }

    public function reinitialiser(Utilisateur $compte): void
    {
        $this->pour($compte)->reset();
    }

    private function pour(Utilisateur $compte): LimiterInterface
    {
        return $this->limiteur->create('compte-'.$compte->getId());
    }
}
