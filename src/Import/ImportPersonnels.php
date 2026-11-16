<?php

namespace App\Import;

use App\Enum\Role;
use App\Repository\UtilisateurRepository;
use App\Security\Compte\ConventionEmailAdmin;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import CSV de la liste des personnels (C1, H3). Cette classe ne fait que LIRE et VÉRIFIER :
 * elle n'écrit rien en base. Elle rend un rapport ligne par ligne, montré à l'administrateur
 * avant toute création de compte.
 *
 * Colonnes attendues (l'ordre est libre) : nom, prénom, e-mail, rôle. Séparateur « ; » ou « , ».
 * L'import ne crée JAMAIS de compte administrateur (C5).
 */
final class ImportPersonnels
{
    public const TAILLE_MAX = 1_048_576; // 1 Mo
    public const LIGNES_MAX = 500;

    private const COLONNES = ['nom', 'prenom', 'email', 'role'];

    /** Écritures acceptées dans la colonne « rôle », en minuscules et sans accents. */
    private const ROLES = [
        'role_user' => Role::User, 'utilisateur' => Role::User, 'user' => Role::User,
        'role_technicien' => Role::Technicien, 'technicien' => Role::Technicien, 'technician' => Role::Technicien,
        'role_direction' => Role::Direction, 'direction' => Role::Direction, 'management' => Role::Direction,
    ];
    private const ROLES_ADMIN = ['role_admin', 'admin', 'administrateur', 'administrator'];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly ConventionEmailAdmin $conventionAdmin,
    ) {
    }

    /** Lit le contenu d'un fichier CSV et vérifie chaque ligne. */
    public function analyserFichier(string $contenu): RapportImport
    {
        if (\strlen($contenu) > self::TAILLE_MAX) {
            return RapportImport::refuse('import.error.too_big');
        }

        // Fichier enregistré par Excel : souvent en Windows-1252 plutôt qu'en UTF-8
        $contenu = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        $flux = fopen('php://memory', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        $premiereLigne = (string) fgets($flux);
        $separateur = substr_count($premiereLigne, ';') >= substr_count($premiereLigne, ',') ? ';' : ',';
        rewind($flux);

        $entete = fgetcsv($flux, null, $separateur, '"', '');
        $positions = \is_array($entete) ? $this->positionsDesColonnes($entete) : null;
        if (null === $positions) {
            fclose($flux);

            return RapportImport::refuse('import.error.header');
        }

        $lignes = [];
        $numero = 1;
        while (false !== ($champs = fgetcsv($flux, null, $separateur, '"', ''))) {
            ++$numero;
            if ([null] === $champs || '' === trim(implode('', $champs))) {
                continue; // ligne vide
            }
            if (\count($lignes) >= self::LIGNES_MAX) {
                fclose($flux);

                return RapportImport::refuse('import.error.too_many', ['%max%' => (string) self::LIGNES_MAX]);
            }

            $lignes[] = [
                'numero' => $numero,
                'nom' => trim((string) ($champs[$positions['nom']] ?? '')),
                'prenom' => trim((string) ($champs[$positions['prenom']] ?? '')),
                'email' => mb_strtolower(trim((string) ($champs[$positions['email']] ?? ''))),
                'role' => trim((string) ($champs[$positions['role']] ?? '')),
            ];
        }
        fclose($flux);

        if ([] === $lignes) {
            return RapportImport::refuse('import.error.empty');
        }

        return $this->verifier($lignes);
    }

    /**
     * Vérifie des lignes déjà lues. Appelée une seconde fois juste avant l'écriture : entre l'aperçu
     * et la confirmation, un compte a pu être créé avec la même adresse.
     *
     * @param list<array{numero: int, nom: string, prenom: string, email: string, role: string}> $lignes
     */
    public function verifier(array $lignes): RapportImport
    {
        $dejaEnBase = [];
        foreach ($this->utilisateurs->findBy(['email' => array_column($lignes, 'email')]) as $compte) {
            $dejaEnBase[$compte->getEmail()] = true;
        }

        $vus = [];
        $resultat = [];
        foreach ($lignes as $ligne) {
            $erreurs = [];

            if ('' === $ligne['nom'] || mb_strlen($ligne['nom']) > 100) {
                $erreurs[] = ['cle' => 'import.error.last_name', 'params' => []];
            }
            if ('' === $ligne['prenom'] || mb_strlen($ligne['prenom']) > 100) {
                $erreurs[] = ['cle' => 'import.error.first_name', 'params' => []];
            }

            $email = $ligne['email'];
            if ('' === $email || mb_strlen($email) > 180 || \count($this->validator->validate($email, new Email())) > 0) {
                $erreurs[] = ['cle' => 'import.error.email', 'params' => []];
            } elseif (isset($vus[$email])) {
                $erreurs[] = ['cle' => 'import.error.duplicate', 'params' => ['%ligne%' => (string) $vus[$email]]];
            } elseif (isset($dejaEnBase[$email])) {
                $erreurs[] = ['cle' => 'import.error.exists', 'params' => []];
            } elseif ($this->conventionAdmin->estUneAdresseAdmin($email)) {
                $erreurs[] = ['cle' => 'import.error.admin_email', 'params' => []];
            }
            $vus[$email] ??= $ligne['numero'];

            $role = $this->lireRole($ligne['role']);
            if (\in_array($this->simplifier($ligne['role']), self::ROLES_ADMIN, true)) {
                $erreurs[] = ['cle' => 'import.error.admin_role', 'params' => []];
            } elseif (null === $role) {
                $erreurs[] = ['cle' => 'import.error.role', 'params' => []];
            }

            $resultat[] = new LigneImport($ligne['numero'], $ligne['nom'], $ligne['prenom'], $email, $ligne['role'], $role, $erreurs);
        }

        return new RapportImport($resultat);
    }

    /**
     * @param list<string|null> $entete
     *
     * @return array<string, int>|null position de chaque colonne attendue, ou null s'il en manque une
     */
    private function positionsDesColonnes(array $entete): ?array
    {
        $positions = [];
        foreach ($entete as $position => $titre) {
            $titre = preg_replace('/[^a-z]/', '', $this->simplifier((string) $titre));
            $titre = \in_array($titre, ['mail', 'courriel', 'adresseemail'], true) ? 'email' : $titre;
            if (\in_array($titre, self::COLONNES, true)) {
                $positions[$titre] ??= $position;
            }
        }

        return \count($positions) === \count(self::COLONNES) ? $positions : null;
    }

    private function lireRole(string $saisie): ?Role
    {
        return self::ROLES[$this->simplifier($saisie)] ?? null;
    }

    private function simplifier(string $texte): string
    {
        $sansAccents = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', trim($texte));

        return false === $sansAccents ? mb_strtolower(trim($texte)) : $sansAccents;
    }
}
