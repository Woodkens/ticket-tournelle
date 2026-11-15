<?php

namespace App\Command;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Langue;
use App\Enum\Role;
use App\Security\Compte\GestionComptes;
use App\Security\Compte\PolitiqueMotDePasse;
use App\Security\Compte\RegleCompteException;
use App\Security\JournalAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Crée un compte depuis le terminal du serveur : sert surtout au PREMIER compte administrateur,
 * avant que l'écran d'administration soit utilisable.
 *
 * Le mot de passe est demandé en saisie cachée, deux fois. Il n'existe AUCUNE option pour le passer
 * en argument : il resterait sinon dans l'historique du shell et dans la liste des processus.
 *
 * Compte administrateur (C5) : adresse dédiée, 15 caractères minimum, et l'enrôlement TOTP est forcé
 * à la première connexion (aucun accès avant).
 */
#[AsCommand(
    name: 'app:create-user',
    description: 'Crée un compte (mot de passe saisi en caché, jamais en argument).',
)]
final class CreateUserCommand extends Command
{
    /** Les messages de la console sont en français : elle n'est utilisée que par le binôme, sur le serveur. */
    private const LANGUE_CONSOLE = 'fr';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GestionComptes $comptes,
        private readonly PolitiqueMotDePasse $politique,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly JournalAuditLogger $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Adresse e-mail professionnelle (identifiant de connexion)')
            ->addArgument('nom', InputArgument::OPTIONAL, 'Nom')
            ->addArgument('prenom', InputArgument::OPTIONAL, 'Prénom')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'ROLE_USER, ROLE_TECHNICIEN, ROLE_DIRECTION ou ROLE_ADMIN', Role::User->value)
            ->addOption('langue', null, InputOption::VALUE_REQUIRED, 'fr ou en', Langue::Fr->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Sans terminal interactif, impossible de saisir le mot de passe en caché : on refuse.
        if (!$input->isInteractive()) {
            $io->error('Cette commande doit être lancée dans un terminal interactif : le mot de passe est saisi en caché.');

            return Command::FAILURE;
        }

        $role = Role::tryFrom((string) $input->getOption('role'));
        $langue = Langue::tryFrom((string) $input->getOption('langue'));
        if (null === $role || null === $langue) {
            $io->error('Rôle ou langue inconnu. Rôles : ROLE_USER, ROLE_TECHNICIEN, ROLE_DIRECTION, ROLE_ADMIN. Langues : fr, en.');

            return Command::FAILURE;
        }

        $email = mb_strtolower(trim((string) ($input->getArgument('email') ?? $io->ask('Adresse e-mail professionnelle'))));
        $nom = trim((string) ($input->getArgument('nom') ?? $io->ask('Nom')));
        $prenom = trim((string) ($input->getArgument('prenom') ?? $io->ask('Prénom')));

        $erreurs = $this->verifier($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)]);
        $erreurs = [...$erreurs, ...$this->verifier($nom, [new Assert\NotBlank(), new Assert\Length(max: 100)])];
        $erreurs = [...$erreurs, ...$this->verifier($prenom, [new Assert\NotBlank(), new Assert\Length(max: 100)])];
        if ([] !== $erreurs) {
            $io->error(['E-mail, nom ou prénom invalide :', ...$erreurs]);

            return Command::FAILURE;
        }

        // Adresse libre, et adresse dédiée obligatoire pour un administrateur (D-19)
        try {
            $this->comptes->verifierAdresse($email, $role);
        } catch (RegleCompteException $refus) {
            $io->error($this->translator->trans($refus->getMessage(), $refus->parametres, 'validators', self::LANGUE_CONSOLE));

            return Command::FAILURE;
        }

        $compte = new Utilisateur($email, $nom, $prenom, $role);
        $compte->setLangue($langue);

        $motDePasse = $this->demanderEnCache($io, sprintf('Mot de passe (%d caractères minimum, saisie cachée)', $this->politique->longueurMinimale($compte)));
        $confirmation = $this->demanderEnCache($io, 'Confirmation du mot de passe');

        if ($motDePasse !== $confirmation) {
            $io->error('Les deux saisies sont différentes. Aucun compte créé.');

            return Command::FAILURE;
        }

        // Même politique que sur le site (ENF-02) : longueur, liste locale, informations personnelles, solidité
        $refus = $this->politique->verifier($motDePasse, $compte);
        if ([] !== $refus) {
            $io->error([
                'Mot de passe refusé (ENF-02) :',
                ...array_map(fn ($message): string => $message->trans($this->translator, self::LANGUE_CONSOLE), $refus),
            ]);

            return Command::FAILURE;
        }

        // Seule l'empreinte Argon2id est enregistrée (ENF-01)
        $compte->setMotDePasseHash($this->hasher->hashPassword($compte, $motDePasse));
        // La personne vient de choisir elle-même son mot de passe : pas de lien d'activation à attendre (ENF-04)
        $compte->setDoitChangerMdp(false);

        $this->entityManager->persist($compte);
        $this->entityManager->flush();

        $this->journal->tracerCompte(ActionAudit::CompteCree, null, $compte, sprintf('%s (console)', $role->value));

        $io->success(sprintf('Compte créé : %s (%s).', $email, $role->value));
        if ($compte->estAdmin()) {
            $io->note('Compte administrateur : à la première connexion, l\'activation de la double authentification (QR code) sera obligatoire.');
        }

        return Command::SUCCESS;
    }

    /** Saisie cachée. Si le terminal ne sait pas masquer la saisie, on arrête au lieu d'afficher le mot de passe. */
    private function demanderEnCache(SymfonyStyle $io, string $libelle): string
    {
        $question = (new Question($libelle))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setTrimmable(false);

        // On garde les espaces éventuels du mot de passe, mais pas le retour à la ligne de la touche Entrée
        return rtrim((string) $io->askQuestion($question), "\r\n");
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $contraintes
     *
     * @return list<string>
     */
    private function verifier(string $valeur, array $contraintes): array
    {
        $messages = [];
        foreach ($this->validator->validate($valeur, $contraintes) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
