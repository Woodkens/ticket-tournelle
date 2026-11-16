<?php

namespace App\Tests\Controller\Admin;

use App\Tests\Support\FonctionnelTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Import CSV des personnels (C1, H3, D-25) : aperçu avec les erreurs avant toute écriture,
 * « tout ou rien », jamais de compte administrateur.
 */
final class ImportAdminTest extends FonctionnelTestCase
{
    private const ADMIN = 'alice.admin+admin@latournelle.example';

    /** @var list<string> fichiers temporaires à effacer après le test */
    private array $fichiers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $motDePasse = $this->motDePasseAleatoire();
        [, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);
        $this->seConnecterEnAdmin(self::ADMIN, $motDePasse, $secret);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->fichiers, 'is_file'));
        parent::tearDown();
    }

    public function testLApercuMontreLesErreursLigneParLigneSansRienEcrire(): void
    {
        $this->creerUtilisateur('deja.la@latournelle.example', $this->motDePasseAleatoire());

        $page = $this->envoyer(<<<'CSV'
            nom;prenom;email;role
            Martin;Claire;claire.martin@latournelle.example;utilisateur
            Bernard;Thomas;pas-une-adresse;utilisateur
            Petit;Nadia;claire.martin@latournelle.example;technicien
            Leroy;Hugo;hugo.leroy@latournelle.example;chef
            Moreau;Ines;ines.moreau@latournelle.example;ROLE_ADMIN
            Roux;Lea;deja.la@latournelle.example;utilisateur
            ;Paul;paul.simon@latournelle.example;direction
            CSV);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('6 lignes en erreur sur 7', $page->filter('.alert-danger')->text());

        self::assertStringContainsString('Valide', $page->filter('tr[data-ligne="2"]')->text());
        self::assertStringContainsString('Adresse e-mail invalide.', $page->filter('tr[data-ligne="3"]')->text());
        self::assertStringContainsString('en double dans le fichier (déjà présente ligne 2)', $page->filter('tr[data-ligne="4"]')->text());
        self::assertStringContainsString('Rôle inconnu', $page->filter('tr[data-ligne="5"]')->text());
        self::assertStringContainsString('ne crée jamais de compte administrateur', $page->filter('tr[data-ligne="6"]')->text());
        self::assertStringContainsString('Un compte existe déjà', $page->filter('tr[data-ligne="7"]')->text());
        self::assertStringContainsString('Nom manquant', $page->filter('tr[data-ligne="8"]')->text());

        // Aucun bouton de confirmation, et rien n'a été écrit ni envoyé
        self::assertCount(0, $page->filter('form[action$="/import/confirmer"]'));
        self::assertSame(2, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM utilisateur'));
        self::assertEmailCount(0);
        self::assertNotContains('USER_CREATED', $this->actionsDuJournal());
    }

    public function testImportDuFichierDExemple(): void
    {
        $page = $this->envoyer((string) file_get_contents(\dirname(__DIR__, 3).'/docs/exemples/import-personnels-exemple.csv'));

        // Étape 1 : aperçu, toujours rien en base
        self::assertStringContainsString('6 lignes vérifiées, aucune erreur', $page->filter('.alert-success')->text());
        self::assertSame(1, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM utilisateur'));

        // Étape 2 : confirmation
        $this->client->submit($page->filter('form[action$="/import/confirmer"]')->form());
        self::assertEmailCount(6);
        self::assertResponseRedirects('/fr/admin/comptes');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('6 comptes créés', $page->filter('.alert-success')->text());

        // 6 comptes en attente d'activation, aucun administrateur, accents conservés
        $comptes = $this->connexionSql()->fetchAllAssociative('SELECT * FROM utilisateur WHERE email <> ? ORDER BY nom', [self::ADMIN]);
        self::assertCount(6, $comptes);
        foreach ($comptes as $compte) {
            self::assertNotSame('ROLE_ADMIN', $compte['role']);
            self::assertSame(1, (int) $compte['doit_changer_mdp']);
        }
        self::assertSame('Inès', $this->compteEnBase('ines.moreau@lycee-exemple.example')['prenom']);
        self::assertSame('ROLE_TECHNICIEN', $this->compteEnBase('nadia.petit@lycee-exemple.example')['role']);
        self::assertSame('ROLE_DIRECTION', $this->compteEnBase('hugo.leroy@lycee-exemple.example')['role']);
        self::assertSame(6, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM jeton_compte'));

        // Journal : une ligne par compte, plus la ligne de l'import
        $actions = array_count_values($this->actionsDuJournal());
        self::assertSame(6, $actions['USER_CREATED']);
        self::assertSame(6, $actions['ACTIVATION_SENT']);
        self::assertSame(1, $actions['USERS_IMPORTED']);
        $import = array_values(array_filter($this->journal(), static fn (array $l): bool => 'USERS_IMPORTED' === $l['action']))[0];
        self::assertSame('6 comptes créés', $import['detail']);
        self::assertNotNull($import['id_acteur']);
    }

    public function testToutOuRienSiUnCompteApparaitEntreLApercuEtLaConfirmation(): void
    {
        $page = $this->envoyer("nom;prenom;email;role\nMartin;Claire;claire.martin@latournelle.example;utilisateur\nPetit;Nadia;nadia.petit@latournelle.example;technicien\n");
        self::assertCount(1, $page->filter('form[action$="/import/confirmer"]'));

        // Pendant que l'administrateur lit l'aperçu, le compte de la ligne 3 est créé par ailleurs
        $this->creerUtilisateur('nadia.petit@latournelle.example', $this->motDePasseAleatoire());

        $page = $this->client->submit($page->filter('form[action$="/import/confirmer"]')->form());

        // Nouvel aperçu avec l'erreur, et AUCUN compte créé (même pas celui de la ligne 2)
        self::assertStringContainsString('Un compte existe déjà', $page->filter('tr[data-ligne="3"]')->text());
        self::assertSame(0, (int) $this->connexionSql()->fetchOne("SELECT COUNT(*) FROM utilisateur WHERE email = 'claire.martin@latournelle.example'"));
        self::assertEmailCount(0);
    }

    public function testConfirmerSansApercuNeCreeRien(): void
    {
        $this->client->request('GET', '/fr/admin/comptes/import');
        $this->client->request('POST', '/fr/admin/comptes/import/confirmer', ['_csrf_token' => $this->jetonCsrf('import-confirmer')]);

        self::assertResponseRedirects('/fr/admin/comptes/import');
        self::assertSame(1, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM utilisateur'));
    }

    public function testUnFichierQuiNEstPasUnCsvEstRefuse(): void
    {
        $page = $this->envoyer("\x89PNG\r\n\x1a\n".random_bytes(64), 'image.png');

        self::assertCount(1, $page->filter('.invalid-feedback'));
        self::assertSame(1, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM utilisateur'));
    }

    private function envoyer(string $contenu, string $nom = 'personnels.csv'): Crawler
    {
        $chemin = sys_get_temp_dir().'/tt-test-'.bin2hex(random_bytes(6)).'-'.$nom;
        file_put_contents($chemin, $contenu);
        $this->fichiers[] = $chemin;

        $page = $this->client->request('GET', '/fr/admin/comptes/import');
        self::assertResponseIsSuccessful();

        $formulaire = $page->filter('form[name="import_personnels"]')->form();
        $formulaire['import_personnels[fichier]']->upload($chemin);

        return $this->client->submit($formulaire);
    }
}
