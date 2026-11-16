<?php

namespace App\Security\Totp;

/**
 * Chiffre les secrets TOTP avant de les écrire en base (ENF-05, D-21).
 *
 * Pourquoi chiffrer et pas hacher : pour vérifier un code à 6 chiffres, le serveur doit RECALCULER
 * ce code à partir du secret, donc pouvoir relire le secret. Un hachage est à sens unique : impossible.
 *
 * Comment : libsodium (extension PHP « sodium », déjà installée), fonction secretbox :
 * chiffrement XSalsa20 + contrôle d'intégrité Poly1305. Si la donnée est modifiée en base, ou si la clé
 * n'est pas la bonne, le déchiffrement échoue au lieu de rendre un faux secret.
 *
 * La clé (32 octets, en base64) est dans .env.local, variable TOTP_ENCRYPTION_KEY : elle n'est ni dans
 * Git ni dans la base. Un vol de la base seule ne donne donc pas les secrets TOTP.
 */
final class ChiffreurTotp
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $cleTotpBase64,
    ) {
    }

    public function chiffrer(#[\SensitiveParameter] string $secret): string
    {
        // Le « nonce » est un nombre aléatoire tiré à chaque chiffrement : deux secrets identiques
        // donnent deux textes chiffrés différents. Il n'est pas secret, on le range devant le résultat.
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->cle()));
    }

    public function dechiffrer(string $secretChiffre): string
    {
        $brut = base64_decode($secretChiffre, true);
        if (false === $brut || \strlen($brut) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secret TOTP illisible.');
        }

        $nonce = substr($brut, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secret = sodium_crypto_secretbox_open(substr($brut, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->cle());
        if (false === $secret) {
            throw new \RuntimeException('Secret TOTP illisible : la clé a changé ou la donnée a été modifiée.');
        }

        return $secret;
    }

    private function cle(): string
    {
        $cle = base64_decode($this->cleTotpBase64, true);
        if (false === $cle || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($cle)) {
            throw new \RuntimeException('TOTP_ENCRYPTION_KEY est absente ou invalide : 32 octets en base64 attendus dans .env.local (voir README).');
        }

        return $cle;
    }
}
