<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Toutes les dates sont calculées et enregistrées en temps universel (UTC), sur le site comme
     * en console (D-26). MariaDB reçoit le même réglage à chaque connexion (config/packages/doctrine.yaml)
     * et Twig affiche les dates à l'heure de Paris (config/packages/twig.yaml).
     * Sans cela, PHP (UTC) et MariaDB (heure du serveur) avaient une heure d'écart.
     */
    public function boot(): void
    {
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
