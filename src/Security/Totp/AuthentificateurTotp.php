<?php

namespace App\Security\Totp;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Security\JournalAuditLogger;
use Doctrine\DBAL\Connection;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Vérifie les codes TOTP à la place de la classe fournie par scheb/2fa-bundle (D-21), pour ajouter :
 *  - le secret CHIFFRÉ en base, déchiffré seulement ici, le temps du calcul ;
 *  - l'ANTI-REJEU : un code accepté ne peut pas resservir, même pendant ses 30 secondes de validité ;
 *  - la limite d'essais (LimiteurTotp).
 *
 * Rappel TOTP (RFC 6238) : le téléphone et le serveur partagent un secret. Toutes les 30 secondes
 * (un « créneau »), chacun calcule de son côté un code à 6 chiffres à partir du secret et de l'heure.
 * Si les deux codes sont identiques, la personne possède bien le téléphone enrôlé.
 */
#[AsDecorator('scheb_two_factor.security.totp_authenticator')]
final class AuthentificateurTotp implements TotpAuthenticatorInterface
{
    public const PERIODE = 30;
    public const CHIFFRES = 6;
    /** On accepte aussi le créneau d'avant et celui d'après : l'horloge du téléphone n'est jamais exacte. */
    private const CRENEAUX_TOLERES = [0, -1, 1];

    public function __construct(
        private readonly ChiffreurTotp $chiffreur,
        private readonly LimiteurTotp $limiteur,
        private readonly Connection $connexion,
        private readonly ClockInterface $horloge,
        private readonly JournalAuditLogger $journal,
    ) {
    }

    public function checkCode(TwoFactorInterface $user, #[\SensitiveParameter] string $code): bool
    {
        if (!$user instanceof Utilisateur || null === $user->getTotpSecret() || $this->limiteur->estBloque($user)) {
            return false;
        }

        $code = (string) preg_replace('/\s+/', '', $code);
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $totp = $this->totp($user);
        $creneauActuel = intdiv($this->horloge->now()->getTimestamp(), self::PERIODE);

        foreach (self::CRENEAUX_TOLERES as $decalage) {
            $creneau = $creneauActuel + $decalage;

            // hash_equals compare en temps constant : la durée ne dit pas combien de chiffres sont bons
            if (hash_equals($totp->at($creneau * self::PERIODE), $code)) {
                return $this->reserverCreneau($user, $creneau);
            }
        }

        return false;
    }

    /** Contenu du QR code : adresse « otpauth:// » comprise par les applications d'authentification. */
    public function getQRContent(TwoFactorInterface $user): string
    {
        if (!$user instanceof Utilisateur || null === $user->getTotpSecret()) {
            throw new \LogicException('Aucun secret TOTP à afficher pour ce compte.');
        }

        $totp = $this->totp($user);
        $totp->setLabel($user->getEmail());
        $totp->setIssuer("Ticket'Tournelle");

        return $totp->getProvisioningUri();
    }

    /** 20 octets aléatoires (160 bits, taille conseillée par la RFC 4226), écrits en base32 pour les applications. */
    public function generateSecret(): string
    {
        return TOTP::generate($this->horloge, 20)->getSecret();
    }

    /**
     * Anti-rejeu : on note en base le numéro du créneau accepté, et on n'accepte ensuite que des créneaux
     * plus récents. Tout est fait en UNE requête SQL préparée : si deux connexions présentent le même code
     * en même temps, une seule réussit la mise à jour.
     */
    private function reserverCreneau(Utilisateur $compte, int $creneau): bool
    {
        $lignesModifiees = $this->connexion->executeStatement(
            'UPDATE utilisateur SET totp_dernier_creneau = :creneau
              WHERE id_utilisateur = :id AND (totp_dernier_creneau IS NULL OR totp_dernier_creneau < :creneau)',
            ['creneau' => $creneau, 'id' => $compte->getId()],
        );

        if (1 === $lignesModifiees) {
            return true;
        }

        $this->journal->tracerCompte(ActionAudit::TotpRejoue, $compte, $compte);

        return false;
    }

    private function totp(Utilisateur $compte): TOTP
    {
        $totp = TOTP::createFromSecret($this->chiffreur->dechiffrer((string) $compte->getTotpSecret()), $this->horloge);
        $totp->setPeriod(self::PERIODE);
        $totp->setDigits(self::CHIFFRES);
        $totp->setDigest('sha1'); // algorithme compris par toutes les applications d'authentification

        return $totp;
    }
}
