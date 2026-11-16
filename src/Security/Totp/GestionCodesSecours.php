<?php

namespace App\Security\Totp;

use App\Entity\CodeSecours;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Repository\CodeSecoursRepository;
use App\Security\JournalAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Codes de secours de la double authentification (ENF-05, CdC §7.1) : 8 codes par administrateur,
 * pour se connecter si le téléphone est perdu. Chaque code ne sert qu'une fois.
 *
 * Ils sont affichés une seule fois, à la fin de l'enrôlement, puis stockés hachés en Argon2id,
 * comme les mots de passe : la base ne permet pas de les relire.
 */
final class GestionCodesSecours implements BackupCodeManagerInterface
{
    public const NOMBRE = 8;
    /** 31 symboles sans 0/O ni 1/I/L : pas de confusion en recopiant. 12 symboles = environ 59 bits d'aléa. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LONGUEUR = 12;

    /** Code reconnu par isBackupCode(), gardé le temps de la requête pour ne pas recalculer les empreintes. */
    private ?CodeSecours $codeReconnu = null;

    public function __construct(
        private readonly CodeSecoursRepository $codes,
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly LimiteurTotp $limiteur,
        private readonly JournalAuditLogger $journal,
    ) {
    }

    /**
     * Crée 8 nouveaux codes et révoque les anciens.
     *
     * @return list<string> les codes en clair, à afficher UNE fois (ex. « ABCD-EFGH-JKMN »)
     */
    public function generer(Utilisateur $compte): array
    {
        $this->revoquer($compte);

        $clairs = [];
        for ($i = 0; $i < self::NOMBRE; ++$i) {
            $code = '';
            for ($j = 0; $j < self::LONGUEUR; ++$j) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }

            $this->entityManager->persist(new CodeSecours($compte, $this->hasher()->hash($code)));
            $clairs[] = implode('-', str_split($code, 4));
        }
        $this->entityManager->flush();

        return $clairs;
    }

    /** Aucune ligne n'est supprimée : les codes restants sont marqués comme inutilisables. */
    public function revoquer(Utilisateur $compte): void
    {
        foreach ($this->codes->findUtilisables($compte) as $code) {
            $code->invalider();
        }
        $this->entityManager->flush();
    }

    public function isBackupCode(object $user, #[\SensitiveParameter] string $code): bool
    {
        $this->codeReconnu = null;

        if (!$user instanceof Utilisateur || $this->limiteur->estBloque($user)) {
            return false;
        }

        // Un code TOTP à 6 chiffres n'a pas la forme d'un code de secours : inutile de calculer 8 empreintes
        $code = $this->normaliser($code);
        if (self::LONGUEUR !== \strlen($code)) {
            return false;
        }

        foreach ($this->codes->findUtilisables($user) as $candidat) {
            if ($this->hasher()->verify($candidat->getCodeHash(), $code)) {
                $this->codeReconnu = $candidat;

                return true;
            }
        }

        return false;
    }

    /** Usage unique : le code qui vient de servir est marqué utilisé, et son emploi est journalisé (sans le code). */
    public function invalidateBackupCode(object $user, #[\SensitiveParameter] string $code): void
    {
        if (!$user instanceof Utilisateur || (null === $this->codeReconnu && !$this->isBackupCode($user, $code))) {
            return;
        }

        $this->codeReconnu?->invalider();
        $this->codeReconnu = null;
        $this->entityManager->flush();

        $restants = \count($this->codes->findUtilisables($user));
        $this->journal->tracerCompte(ActionAudit::CodeSecoursUtilise, $user, $user, sprintf('codes restants : %d', $restants));
    }

    /** Majuscules, sans espaces ni tirets : « abcd-efgh jkmn » devient « ABCDEFGHJKMN ». */
    private function normaliser(#[\SensitiveParameter] string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hasherFactory->getPasswordHasher(Utilisateur::class);
    }
}
