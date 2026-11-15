<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Les tests des pages d'erreur démarrent l'application SANS le mode debug, comme en production.
// Dans ce mode, Symfony ne vérifie pas si son cache est à jour : on repart donc d'un cache vide
// à chaque lancement, pour ne jamais tester une ancienne configuration ou d'anciennes traductions.
(new Filesystem())->remove(dirname(__DIR__).'/var/cache/test');
