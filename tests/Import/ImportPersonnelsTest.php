<?php

namespace App\Tests\Import;

use App\Enum\Role;
use App\Import\ImportPersonnels;
use App\Tests\Support\BaseDeTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lecture et vérification du fichier CSV des personnels (C1) : aucune écriture en base.
 */
final class ImportPersonnelsTest extends KernelTestCase
{
    use BaseDeTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->viderLaBase();
    }

    public function testFichierAvecPointsVirgules(): void
    {
        $rapport = $this->import()->analyserFichier("nom;prenom;email;role\nMartin;Claire;Claire.Martin@Lycee.Example;Technicien\n");

        self::assertTrue($rapport->estValide());
        self::assertCount(1, $rapport->lignes);
        self::assertSame(2, $rapport->lignes[0]->numero);
        self::assertSame('claire.martin@lycee.example', $rapport->lignes[0]->email);
        self::assertSame(Role::Technicien, $rapport->lignes[0]->role);
    }

    public function testVirgulesGuillemetsEtColonnesDansUnAutreOrdre(): void
    {
        $rapport = $this->import()->analyserFichier("E-mail,Rôle,Prénom,Nom\n\"hugo.leroy@lycee.example\",direction,Hugo,\"Leroy, dit Le Roux\"\n");

        self::assertTrue($rapport->estValide());
        self::assertSame('Leroy, dit Le Roux', $rapport->lignes[0]->nom);
        self::assertSame(Role::Direction, $rapport->lignes[0]->role);
    }

    public function testFichierExcelEnWindows1252AvecAccents(): void
    {
        $contenu = mb_convert_encoding("nom;prénom;email;rôle\nMoreau;Inès;ines.moreau@lycee.example;utilisateur\n", 'Windows-1252', 'UTF-8');

        $rapport = $this->import()->analyserFichier($contenu);

        self::assertTrue($rapport->estValide());
        self::assertSame('Inès', $rapport->lignes[0]->prenom);
    }

    public function testMarqueDOrdreDOctetsEtLignesVidesIgnorees(): void
    {
        $rapport = $this->import()->analyserFichier("\xEF\xBB\xBFnom;prenom;email;role\n\nMartin;Claire;claire.martin@lycee.example;user\n;;;\n");

        self::assertTrue($rapport->estValide());
        self::assertCount(1, $rapport->lignes);
    }

    public function testEnTeteIncompletRefuse(): void
    {
        $rapport = $this->import()->analyserFichier("nom;prenom;email\nMartin;Claire;claire.martin@lycee.example\n");

        self::assertFalse($rapport->estValide());
        self::assertSame('import.error.header', $rapport->erreurFichier['cle'] ?? null);
    }

    public function testFichierSansDonneesOuTropLongRefuse(): void
    {
        self::assertSame('import.error.empty', $this->import()->analyserFichier("nom;prenom;email;role\n")->erreurFichier['cle'] ?? null);

        $lignes = "nom;prenom;email;role\n";
        for ($i = 1; $i <= 501; ++$i) {
            $lignes .= sprintf("Nom%d;Prenom;personne%d@lycee.example;utilisateur\n", $i, $i);
        }
        self::assertSame('import.error.too_many', $this->import()->analyserFichier($lignes)->erreurFichier['cle'] ?? null);

        self::assertSame('import.error.too_big', $this->import()->analyserFichier(str_repeat('a', ImportPersonnels::TAILLE_MAX + 1))->erreurFichier['cle'] ?? null);
    }

    public function testLImportNeCreeJamaisDAdministrateur(): void
    {
        $rapport = $this->import()->analyserFichier(<<<'CSV'
            nom;prenom;email;role
            Un;Admin;admin1@lycee.example;ROLE_ADMIN
            Deux;Admin;admin2@lycee.example;Administrateur
            Trois;Admin;admin3+admin@lycee.example;utilisateur
            CSV);

        self::assertFalse($rapport->estValide());
        self::assertSame(3, $rapport->nombreDErreurs());
        self::assertSame('import.error.admin_role', $rapport->lignes[0]->erreurs[0]['cle']);
        self::assertSame('import.error.admin_role', $rapport->lignes[1]->erreurs[0]['cle']);
        // Une adresse au format « compte admin » ne passe pas non plus par l'import
        self::assertSame('import.error.admin_email', $rapport->lignes[2]->erreurs[0]['cle']);
    }

    public function testCompteDejaExistantSignale(): void
    {
        $this->creerUtilisateur('claire.martin@lycee.example', $this->motDePasseAleatoire());

        $rapport = $this->import()->analyserFichier("nom;prenom;email;role\nMartin;Claire;CLAIRE.MARTIN@lycee.example;utilisateur\n");

        self::assertSame('import.error.exists', $rapport->lignes[0]->erreurs[0]['cle']);
        self::assertSame(1, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM utilisateur'));
    }

    private function import(): ImportPersonnels
    {
        return static::getContainer()->get(ImportPersonnels::class);
    }
}
