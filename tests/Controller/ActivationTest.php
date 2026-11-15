<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Enum\Role;
use App\Enum\TypeJeton;
use App\Security\Compte\GestionComptes;
use App\Security\Compte\GestionJetons;
use App\Tests\Support\FonctionnelTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Activation d'un compte par lien (US06, ENF-04, D-20) : aucun mot de passe provisoire,
 * jeton stocké haché, usage unique, durée limitée.
 */
final class ActivationTest extends FonctionnelTestCase
{
    private const EMAIL = 'nadia.petit@latournelle.example';

    public function testLeTitulaireChoisitSonMotDePasseAvecLeLien(): void
    {
        [$compte, $jeton] = $this->compteEnAttente();
        $motDePasse = $this->motDePasseAleatoire();

        $page = $this->client->request('GET', '/fr/compte/mot-de-passe/'.$jeton);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Activer mon compte');

        $this->choisir($page, $motDePasse, $motDePasse);
        self::assertResponseRedirects('/fr/login');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Votre compte est activé', $page->filter('.alert-success')->text());

        // Le compte est activé, seule une empreinte Argon2id est en base, et l'activation est tracée
        $ligne = $this->compteEnBase(self::EMAIL);
        self::assertSame(0, (int) $ligne['doit_changer_mdp']);
        self::assertStringStartsWith('$argon2id$', $ligne['mot_de_passe_hash']);
        self::assertContains('USER_ACTIVATED', $this->actionsDuJournal());

        // Et la personne peut se connecter avec le mot de passe qu'elle a choisi
        $this->seConnecter(self::EMAIL, $motDePasse);
        self::assertResponseRedirects('/fr');
        self::assertNotNull($compte->getId());
    }

    public function testLeJetonEstStockeHache(): void
    {
        [, $jeton] = $this->compteEnAttente();

        $ligne = $this->connexionSql()->fetchAssociative('SELECT * FROM jeton_compte');
        self::assertIsArray($ligne);
        self::assertSame(hash('sha256', $jeton), $ligne['jeton_hash']);
        self::assertStringNotContainsString($jeton, json_encode($ligne, \JSON_THROW_ON_ERROR));
        self::assertSame('activation', $ligne['type']);

        // 72 heures de validité pour une activation
        self::assertSame(72 * 3600, strtotime($ligne['date_expiration']) - strtotime($ligne['date_creation']));
    }

    public function testUnLienNeSertQuUneFois(): void
    {
        [, $jeton] = $this->compteEnAttente();
        $motDePasse = $this->motDePasseAleatoire();

        $this->choisir($this->client->request('GET', '/fr/compte/mot-de-passe/'.$jeton), $motDePasse, $motDePasse);
        self::assertResponseRedirects('/fr/login');

        // Second passage sur le même lien : refusé
        $this->client->request('GET', '/fr/compte/mot-de-passe/'.$jeton);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Lien invalide ou expiré');
    }

    public function testUnLienExpireEstRefuse(): void
    {
        [, $jeton] = $this->compteEnAttente();
        $this->connexionSql()->executeStatement('UPDATE jeton_compte SET date_expiration = DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
        $this->oublierLesEntites();

        $this->client->request('GET', '/fr/compte/mot-de-passe/'.$jeton);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Lien invalide ou expiré');
        self::assertSame(1, (int) $this->compteEnBase(self::EMAIL)['doit_changer_mdp']);
    }

    public function testUnLienInconnuDonneLaMemePage(): void
    {
        $this->client->request('GET', '/fr/compte/mot-de-passe/'.str_repeat('A', 43));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Lien invalide ou expiré');
    }

    public function testUnNouveauLienAnnuleLePrecedent(): void
    {
        [$compte, $ancien] = $this->compteEnAttente();
        [, $nouveau] = static::getContainer()->get(GestionJetons::class)->creer($compte, TypeJeton::Activation);

        $this->client->request('GET', '/fr/compte/mot-de-passe/'.$ancien);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/fr/compte/mot-de-passe/'.$nouveau);
        self::assertResponseIsSuccessful();
    }

    public function testLeLienDUnCompteDesactiveEstRefuse(): void
    {
        [, $jeton] = $this->compteEnAttente();
        $this->connexionSql()->executeStatement('UPDATE utilisateur SET actif = 0');
        $this->oublierLesEntites();

        $this->client->request('GET', '/fr/compte/mot-de-passe/'.$jeton);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaPolitiqueDeMotDePasseEstAppliquee(): void
    {
        [, $jeton] = $this->compteEnAttente();
        $adresse = '/fr/compte/mot-de-passe/'.$jeton;

        // Trop court (11 caractères)
        $page = $this->choisir($this->client->request('GET', $adresse), 'Tq!9-xbWzr4', 'Tq!9-xbWzr4');
        self::assertStringContainsString('au moins 12 caractères', $page->filter('.invalid-feedback')->text());

        // Dans la liste locale des mots de passe courants
        $page = $this->choisir($this->client->request('GET', $adresse), 'motdepasse123', 'motdepasse123');
        self::assertStringContainsString('les plus courants', $page->filter('.invalid-feedback')->text());

        // Contient le nom du titulaire
        $page = $this->choisir($this->client->request('GET', $adresse), 'Xk9!Petit-Zp4#vv', 'Xk9!Petit-Zp4#vv');
        self::assertStringContainsString('ni votre nom', $page->filter('.invalid-feedback')->text());

        // Les deux saisies diffèrent
        $page = $this->choisir($this->client->request('GET', $adresse), $this->motDePasseAleatoire(), $this->motDePasseAleatoire());
        self::assertStringContainsString('différents', $page->filter('.invalid-feedback')->text());

        // Rien n'a été enregistré, et le lien n'est pas consommé par un refus
        self::assertSame(1, (int) $this->compteEnBase(self::EMAIL)['doit_changer_mdp']);
        $this->client->request('GET', $adresse);
        self::assertResponseIsSuccessful();
    }

    public function testUnAdministrateurDoitChoisirQuinzeCaracteres(): void
    {
        $comptes = static::getContainer()->get(GestionComptes::class);
        $admin = $comptes->creer(null, 'nadia.petit+admin@latournelle.example', 'Petit', 'Nadia', Role::Admin);
        [, $jeton] = static::getContainer()->get(GestionJetons::class)->creer($admin, TypeJeton::Activation);
        $adresse = '/fr/compte/mot-de-passe/'.$jeton;

        $page = $this->choisir($this->client->request('GET', $adresse), 'Tq!9-xbWzr4kPm', 'Tq!9-xbWzr4kPm'); // 14 caractères
        self::assertStringContainsString('au moins 15 caractères', $page->filter('.invalid-feedback')->text());

        $this->choisir($this->client->request('GET', $adresse), 'Tq!9-xbWzr4kPmY', 'Tq!9-xbWzr4kPmY'); // 15 caractères
        self::assertResponseRedirects('/fr/login');
    }

    public function testUnCompteNonActiveNePeutPasSeConnecter(): void
    {
        $this->compteEnAttente();

        $page = $this->seConnecter(self::EMAIL, $this->motDePasseAleatoire());

        // Même message que pour un mauvais mot de passe ; la vraie raison n'est que dans le journal
        self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());
        self::assertContains('LOGIN_NOT_ACTIVATED', $this->actionsDuJournal());
    }

    /**
     * Compte créé par un administrateur, en attente d'activation.
     *
     * @return array{0: Utilisateur, 1: string} le compte et le jeton en clair (celui du lien de l'e-mail)
     */
    private function compteEnAttente(): array
    {
        $compte = static::getContainer()->get(GestionComptes::class)->creer(null, self::EMAIL, 'Petit', 'Nadia', Role::User);
        [, $jeton] = static::getContainer()->get(GestionJetons::class)->creer($compte, TypeJeton::Activation);

        return [$compte, $jeton];
    }

    private function choisir(Crawler $page, string $motDePasse, string $confirmation): Crawler
    {
        return $this->client->submit($page->filter('form[name="choix_mot_de_passe"]')->form([
            'choix_mot_de_passe[motDePasse][first]' => $motDePasse,
            'choix_mot_de_passe[motDePasse][second]' => $confirmation,
        ]));
    }
}
