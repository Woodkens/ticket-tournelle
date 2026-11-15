<?php

namespace App\Security\Compte;

use App\Entity\JetonCompte;
use App\Entity\Utilisateur;
use App\Enum\TypeJeton;
use App\Repository\JetonCompteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liens d'activation et de réinitialisation (ENF-04, D-20).
 *
 * Le jeton : 32 octets tirés au hasard (256 bits), impossible à deviner. Il part dans le lien de l'e-mail.
 * En base, on ne garde que son empreinte SHA-256. Un hachage rapide suffit ici, contrairement à un mot
 * de passe : le jeton est aléatoire et bien trop long pour être retrouvé par essais successifs.
 *
 * Usage unique, durée limitée (72 h ou 30 min), et tout nouveau lien annule les précédents.
 */
final class GestionJetons
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JetonCompteRepository $jetons,
    ) {
    }

    /**
     * @return array{0: JetonCompte, 1: string} le jeton enregistré (haché) et sa valeur en clair pour l'e-mail
     */
    public function creer(Utilisateur $utilisateur, TypeJeton $type): array
    {
        $this->annulerLesLiens($utilisateur);

        // base64 « URL » : seulement des lettres, chiffres, - et _ (43 caractères pour 32 octets)
        $clair = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $jeton = new JetonCompte($utilisateur, $type, self::empreinte($clair));
        $this->entityManager->persist($jeton);
        $this->entityManager->flush();

        return [$jeton, $clair];
    }

    /** Retrouve un lien encore valable : jamais utilisé ni annulé, pas expiré, compte toujours actif. */
    public function trouverUtilisable(#[\SensitiveParameter] string $clair): ?JetonCompte
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $clair)) {
            return null;
        }

        $jeton = $this->jetons->findOneByHash(self::empreinte($clair));

        return null !== $jeton && $jeton->estUtilisable() && $jeton->getUtilisateur()->isActif() ? $jeton : null;
    }

    /** Usage unique : le lien qui vient de servir et tous les autres liens du compte sont annulés. */
    public function consommer(JetonCompte $jeton): void
    {
        $jeton->invalider();
        $this->annulerLesLiens($jeton->getUtilisateur());
        $this->entityManager->flush();
    }

    /** Aucune ligne n'est supprimée : date_utilisation est remplie. */
    public function annulerLesLiens(Utilisateur $utilisateur): void
    {
        foreach ($this->jetons->findUtilisables($utilisateur) as $ancien) {
            $ancien->invalider();
        }
    }

    public static function empreinte(#[\SensitiveParameter] string $clair): string
    {
        return hash('sha256', $clair);
    }
}
