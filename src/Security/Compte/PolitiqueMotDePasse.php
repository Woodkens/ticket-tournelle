<?php

namespace App\Security\Compte;

use App\Entity\Utilisateur;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Politique de mot de passe (ENF-02, R1 §6.2), au même endroit pour l'écran web et la console :
 *  1. longueur : 12 caractères, 15 pour un administrateur ;
 *  2. pas d'information personnelle (nom, prénom, identifiant) ni de mot du contexte (tournelle, lycée…) ;
 *  3. absent de la liste LOCALE des mots de passe courants (D-23) : aucun appel à un service extérieur ;
 *  4. solidité estimée sur le serveur (PasswordStrength de Symfony).
 */
final class PolitiqueMotDePasse
{
    public const LONGUEUR_MIN = 12;
    public const LONGUEUR_MIN_ADMIN = 15;
    /** Limite de Symfony : au-delà, le hachage coûterait trop cher (déni de service). */
    public const LONGUEUR_MAX = 4096;

    /** Mots que tout le monde au lycée peut deviner. */
    private const MOTS_DU_CONTEXTE = ['tournelle', 'lycee', 'ticket'];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly string $fichierMotsDePasseCourants,
    ) {
    }

    public function longueurMinimale(Utilisateur $compte): int
    {
        return $compte->estAdmin() ? self::LONGUEUR_MIN_ADMIN : self::LONGUEUR_MIN;
    }

    /**
     * @return list<TranslatableMessage> liste vide = mot de passe accepté
     */
    public function verifier(#[\SensitiveParameter] string $motDePasse, Utilisateur $compte): array
    {
        $longueur = mb_strlen($motDePasse);
        $minimum = $this->longueurMinimale($compte);

        if ($longueur < $minimum) {
            return [new TranslatableMessage('password.too_short', ['%min%' => $minimum], 'validators')];
        }
        if ($longueur > self::LONGUEUR_MAX) {
            return [new TranslatableMessage('password.too_long', ['%max%' => self::LONGUEUR_MAX], 'validators')];
        }

        $erreurs = [];
        $simplifie = $this->simplifier($motDePasse);

        foreach ($this->motsInterdits($compte) as $mot) {
            if (str_contains($simplifie, $mot)) {
                $erreurs[] = new TranslatableMessage('password.personal', [], 'validators');
                break;
            }
        }

        if ($this->estCourant($motDePasse)) {
            $erreurs[] = new TranslatableMessage('password.common', [], 'validators');
        }

        // Seuil « faible » de Symfony = au moins 60 bits d'entropie estimée. Le seuil au-dessus (80 bits) refuserait
        // TOUT mot de passe de 12 caractères, même tiré au hasard : il contredirait la longueur fixée par ENF-02.
        // Ce contrôle sert à écarter les saisies répétitives (« aaaaaaaaaaaa », « abababababab »).
        $tropSimple = $this->validator->validate($motDePasse, new PasswordStrength(minScore: PasswordStrength::STRENGTH_WEAK));
        if (\count($tropSimple) > 0) {
            $erreurs[] = new TranslatableMessage('password.too_weak', [], 'validators');
        }

        return $erreurs;
    }

    /**
     * Nom, prénom, morceaux de l'adresse e-mail (4 lettres au moins) et mots du contexte.
     *
     * @return list<string>
     */
    private function motsInterdits(Utilisateur $compte): array
    {
        $identifiant = (string) strstr($compte->getEmail(), '@', true);
        $candidats = [
            $compte->getNom(),
            $compte->getPrenom(),
            $identifiant,
            ...(preg_split('/[.+_-]+/', $identifiant) ?: []),
        ];

        $mots = self::MOTS_DU_CONTEXTE;
        foreach ($candidats as $candidat) {
            $candidat = $this->simplifier($candidat);
            // « admin » fait partie de l'adresse d'un compte administrateur : trop courant pour l'interdire à part
            if (mb_strlen($candidat) >= 4 && 'admin' !== $candidat) {
                $mots[] = $candidat;
            }
        }

        return array_values(array_unique($mots));
    }

    /** Recherche dans le fichier local, ligne par ligne, sans tenir compte des majuscules. */
    private function estCourant(#[\SensitiveParameter] string $motDePasse): bool
    {
        $cherche = mb_strtolower($motDePasse);
        $fichier = new \SplFileObject($this->fichierMotsDePasseCourants);
        $fichier->setFlags(\SplFileObject::DROP_NEW_LINE);

        foreach ($fichier as $ligne) {
            if ($ligne === $cherche && !str_starts_with((string) $ligne, '#')) {
                return true;
            }
        }

        return false;
    }

    /** Minuscules et sans accents : « Lycée » et « LYCEE » deviennent « lycee ». */
    private function simplifier(string $texte): string
    {
        $sansAccents = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texte);

        return false === $sansAccents ? mb_strtolower($texte) : $sansAccents;
    }
}
