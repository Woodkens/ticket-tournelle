<?php

namespace App\Import;

/** Résultat de la vérification d'un fichier : l'aperçu montré avant toute écriture. */
final class RapportImport
{
    /**
     * @param list<LigneImport>                                         $lignes
     * @param array{cle: string, params: array<string, string>}|null $erreurFichier le fichier entier est refusé
     */
    public function __construct(
        public readonly array $lignes,
        public readonly ?array $erreurFichier = null,
    ) {
    }

    /** @param array<string, string> $params */
    public static function refuse(string $cle, array $params = []): self
    {
        return new self([], ['cle' => $cle, 'params' => $params]);
    }

    /** « Tout ou rien » (D-25) : l'import n'est possible que si AUCUNE ligne n'a d'erreur. */
    public function estValide(): bool
    {
        return null === $this->erreurFichier && [] !== $this->lignes && 0 === $this->nombreDErreurs();
    }

    public function nombreDErreurs(): int
    {
        return \count(array_filter($this->lignes, static fn (LigneImport $ligne): bool => !$ligne->estValide()));
    }

    /**
     * Lignes sous une forme simple, rangée dans la session entre l'aperçu et la confirmation.
     *
     * @return list<array{numero: int, nom: string, prenom: string, email: string, role: string}>
     */
    public function enTableau(): array
    {
        return array_map(static fn (LigneImport $ligne): array => [
            'numero' => $ligne->numero,
            'nom' => $ligne->nom,
            'prenom' => $ligne->prenom,
            'email' => $ligne->email,
            'role' => $ligne->roleSaisi,
        ], $this->lignes);
    }
}
