<?php

namespace App\Command;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Langue;
use App\Enum\Role;
use App\Repository\UtilisateurRepository;
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

/**
 * Crée un compte depuis le terminal du serveur (premier compte, avant l'écran d'administration de S6).
 *
 * Le mot de passe est demandé en saisie cachée, deux fois. Il n'existe AUCUNE option pour le passer
 * en argument : il resterait sinon dans l'historique du shell et dans la liste des processus.
 */
#[AsCommand(
    name: 'app:create-user',
    description: 'Crée un compte (mot de passe saisi en caché, jamais en argument).',
)]
final class CreateUserCommand extends Command
{
    /** ENF-02 : 12 caractères minimum (15 pour un administrateur, à partir de S6). */
    public const LONGUEUR_MIN_MDP = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
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
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'ROLE_USER, ROLE_TECHNICIEN ou ROLE_DIRECTION', Role::User->value)
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
            $io->error('Rôle ou langue inconnu. Rôles : ROLE_USER, ROLE_TECHNICIEN, ROLE_DIRECTION. Langues : fr, en.');

            return Command::FAILURE;
        }

        // Aucun administrateur sans TOTP : la création des comptes admin arrive en S6 (D-07, ENF-05).
        if (Role::Admin === $role) {
            $io->error('Les comptes administrateurs seront créés en S6, avec la double authentification TOTP obligatoire.');

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

        if (null !== $this->utilisateurs->findOneByEmail($email)) {
            $io->error('Un compte existe déjà avec cette adresse e-mail.');

            return Command::FAILURE;
        }

        $motDePasse = $this->demanderEnCache($io, sprintf('Mot de passe (%d caractères minimum, saisie cachée)', self::LONGUEUR_MIN_MDP));
        $confirmation = $this->demanderEnCache($io, 'Confirmation du mot de passe');

        if ($motDePasse !== $confirmation) {
            $io->error('Les deux saisies sont différentes. Aucun compte créé.');

            return Command::FAILURE;
        }

        $erreurs = $this->verifierMotDePasse($motDePasse, $email);
        if ([] !== $erreurs) {
            $io->error(['Mot de passe refusé (ENF-02) :', ...$erreurs]);

            return Command::FAILURE;
        }

        $utilisateur = new Utilisateur($email, $nom, $prenom, $role);
        $utilisateur->setLangue($langue);
        // Seule l'empreinte Argon2id est enregistrée (ENF-01)
        $utilisateur->setMotDePasseHash($this->hasher->hashPassword($utilisateur, $motDePasse));
        // La personne vient de choisir elle-même son mot de passe : il n'est pas provisoire (ENF-04)
        $utilisateur->setDoitChangerMdp(false);

        $this->entityManager->persist($utilisateur);
        $this->entityManager->flush();

        $this->journal->tracer(ActionAudit::CompteCree, null, 'utilisateur', $utilisateur->getId());

        $io->success(sprintf('Compte créé : %s (%s).', $email, $role->value));

        return Command::SUCCESS;
    }

    /** Saisie cachée. Si le terminal ne sait pas masquer la saisie, on arrête au lieu d'afficher le mot de passe. */
    private function demanderEnCache(SymfonyStyle $io, string $libelle): string
    {
        $question = (new Question($libelle))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setTrimmable(false);

        return (string) $io->askQuestion($question);
    }

    /**
     * Politique de mot de passe (ENF-02, R1 §6.2) : longueur, différent de l'identifiant,
     * et solidité estimée sur le serveur lui-même (aucun appel à un service extérieur).
     *
     * @return list<string>
     */
    private function verifierMotDePasse(string $motDePasse, string $email): array
    {
        // Partie de l'e-mail avant le « @ » (ex. « prenom.nom ») : interdite dans le mot de passe
        $identifiant = (string) strstr($email, '@', true);
        $minuscules = mb_strtolower($motDePasse);
        if ($minuscules === $email || (mb_strlen($identifiant) >= 4 && str_contains($minuscules, $identifiant))) {
            return ['Le mot de passe ne doit pas reprendre l\'identifiant.'];
        }

        return $this->verifier($motDePasse, [
            new Assert\Length(
                min: self::LONGUEUR_MIN_MDP,
                max: 4096,
                minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
            ),
            new Assert\PasswordStrength(
                minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
                message: 'Le mot de passe est trop simple à deviner : mélangez davantage de caractères différents.',
            ),
        ]);
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
