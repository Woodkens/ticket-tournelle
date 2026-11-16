<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\Role;
use App\Security\Compte\GestionComptes;
use App\Security\Compte\RegleCompteException;
use App\Security\Compte\ReglesComptes;
use App\Security\Totp\GestionCodesSecours;
use App\Tests\Support\FonctionnelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Administration des comptes (US06, C1, C5, CdC §2.1) : créer, changer de rôle, désactiver, réactiver.
 * Jamais de suppression. Chaque action est journalisée avec l'acteur et la cible (ENF-14).
 */
final class ComptesAdminTest extends FonctionnelTestCase
{
    private const ADMIN = 'alice.admin+admin@latournelle.example';

    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $motDePasse = $this->motDePasseAleatoire();
        [$this->admin, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);
        $this->seConnecterEnAdmin(self::ADMIN, $motDePasse, $secret);
    }

    public function testTableauRechercheEtFiltres(): void
    {
        $this->creerUtilisateur('claire.martin@latournelle.example', $this->motDePasseAleatoire(), nom: 'Martin', prenom: 'Claire');
        $this->creerUtilisateur('nadia.petit@latournelle.example', $this->motDePasseAleatoire(), Role::Technicien, nom: 'Petit', prenom: 'Nadia');
        $this->creerUtilisateur('hugo.leroy@latournelle.example', $this->motDePasseAleatoire(), Role::User, actif: false, nom: 'Leroy', prenom: 'Hugo');

        $page = $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $page->filter('tbody tr[data-compte]'));
        self::assertStringContainsString('Aucun compte n\'est supprimé', $page->filter('.alert-info')->text());

        // L'administrateur connecté : 2FA active et dernière connexion renseignée
        $ligneAdmin = $page->filter(sprintf('tr[data-compte="%d"]', $this->admin->getId()))->text();
        self::assertStringContainsString('Active', $ligneAdmin);
        self::assertMatchesRegularExpression('#\d{2}/\d{2}/\d{4} \d{2}:\d{2}#', $ligneAdmin);

        // Recherche par nom, puis par e-mail
        self::assertCount(1, $this->client->request('GET', '/fr/admin/comptes?q=marti')->filter('tbody tr[data-compte]'));
        self::assertCount(1, $this->client->request('GET', '/fr/admin/comptes?q=nadia.petit@')->filter('tbody tr[data-compte]'));
        // Filtres par rôle et par état
        self::assertCount(1, $this->client->request('GET', '/fr/admin/comptes?role=ROLE_TECHNICIEN')->filter('tbody tr[data-compte]'));
        self::assertCount(1, $this->client->request('GET', '/fr/admin/comptes?etat=desactive')->filter('tbody tr[data-compte]'));
        self::assertCount(3, $this->client->request('GET', '/fr/admin/comptes?etat=actif')->filter('tbody tr[data-compte]'));
        // Une tentative d'injection SQL dans la recherche est traitée comme un simple texte
        self::assertCount(0, $this->client->request('GET', "/fr/admin/comptes?q=' OR 1=1 --")->filter('tbody tr[data-compte]'));
        self::assertResponseIsSuccessful();
    }

    public function testCreationDUnCompteAvecEmailDActivation(): void
    {
        $page = $this->client->request('GET', '/fr/admin/comptes/creer');
        // Aucun champ « mot de passe » : c'est le titulaire qui le choisira
        self::assertCount(0, $page->filter('input[type="password"]'));

        $this->client->submit($page->filter('form[name="compte"]')->form([
            'compte[prenom]' => 'Thomas',
            'compte[nom]' => 'Bernard',
            'compte[email]' => 'Thomas.Bernard@latournelle.example',
            'compte[role]' => 'ROLE_TECHNICIEN',
            'compte[langue]' => 'fr',
        ]));

        // Un e-mail d'activation part vers la personne
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'thomas.bernard@latournelle.example');
        self::assertEmailHeaderSame($email, 'Subject', 'Ticket\'Tournelle : activez votre compte');
        $lien = $this->lienDuDernierEmail();
        self::assertResponseRedirects('/fr/admin/comptes');

        // Compte créé « en attente » : pas de mot de passe utilisable, jeton stocké haché
        $compte = $this->compteEnBase('thomas.bernard@latournelle.example');
        self::assertSame('ROLE_TECHNICIEN', $compte['role']);
        self::assertSame(1, (int) $compte['doit_changer_mdp']);
        self::assertSame(hash('sha256', basename($lien)), $this->connexionSql()->fetchOne('SELECT jeton_hash FROM jeton_compte'));

        // Journal : acteur = l'administrateur, cible = le nouveau compte
        $creation = $this->derniereAction('USER_CREATED');
        self::assertEquals($this->admin->getId(), $creation['id_acteur']);
        self::assertSame('utilisateur', $creation['cible_type']);
        self::assertEquals($compte['id_utilisateur'], $creation['cible_id']);
        self::assertSame('127.0.0.1', $creation['ip']);
        self::assertSame('ROLE_TECHNICIEN (manuel)', $creation['detail']);
        self::assertContains('ACTIVATION_SENT', $this->actionsDuJournal());

        $page = $this->client->followRedirect();
        self::assertStringContainsString('Activation en attente', $page->filter(sprintf('tr[data-compte="%d"]', $compte['id_utilisateur']))->text());
    }

    public function testUnCompteAdminExigeUneAdresseDediee(): void
    {
        // Adresse ordinaire + rôle admin : refusé (C5, comptes séparés)
        $page = $this->creer('Camille', 'Durand', 'camille.durand@latournelle.example', 'ROLE_ADMIN');
        self::assertStringContainsString('adresse dédiée', $page->filter('.invalid-feedback')->text());
        self::assertEmailCount(0);

        // Adresse dédiée + rôle ordinaire : refusé aussi
        $page = $this->creer('Camille', 'Durand', 'camille.durand+admin@latournelle.example', 'ROLE_USER');
        self::assertStringContainsString('réservée aux comptes administrateurs', $page->filter('.invalid-feedback')->text());

        // Adresse dédiée + rôle admin : accepté
        $this->creer('Camille', 'Durand', 'camille.durand+admin@latournelle.example', 'ROLE_ADMIN');
        self::assertResponseRedirects('/fr/admin/comptes');
        self::assertSame('ROLE_ADMIN', $this->compteEnBase('camille.durand+admin@latournelle.example')['role']);
    }

    public function testUneAdresseDejaUtiliseeEstRefusee(): void
    {
        $this->creerUtilisateur('claire.martin@latournelle.example', $this->motDePasseAleatoire());

        $page = $this->creer('Claire', 'Martin', 'CLAIRE.MARTIN@latournelle.example', 'ROLE_USER');

        self::assertStringContainsString('existe déjà', $page->filter('.invalid-feedback')->text());
        self::assertSame(2, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM utilisateur'));
    }

    public function testChangementDeRoleJournalise(): void
    {
        $compte = $this->creerUtilisateur('claire.martin@latournelle.example', $this->motDePasseAleatoire());

        $page = $this->client->request('GET', sprintf('/fr/admin/comptes/%d/modifier', $compte->getId()));
        $this->client->submit($page->filter('form[name="role_compte"]')->form(['role_compte[role]' => 'ROLE_TECHNICIEN']));

        self::assertResponseRedirects('/fr/admin/comptes');
        self::assertSame('ROLE_TECHNICIEN', $this->compteEnBase('claire.martin@latournelle.example')['role']);

        $changement = $this->derniereAction('ROLE_CHANGED');
        self::assertEquals($this->admin->getId(), $changement['id_acteur']);
        self::assertEquals($compte->getId(), $changement['cible_id']);
        self::assertSame('ROLE_USER > ROLE_TECHNICIEN', $changement['detail']);
    }

    public function testImpossibleDePromouvoirUnCompteEnAdministrateur(): void
    {
        $compte = $this->creerUtilisateur('claire.martin@latournelle.example', $this->motDePasseAleatoire());

        // Le rôle admin n'est pas proposé dans la liste…
        $page = $this->client->request('GET', sprintf('/fr/admin/comptes/%d/modifier', $compte->getId()));
        self::assertCount(0, $page->filter('select[name="role_compte[role]"] option[value="ROLE_ADMIN"]'));

        // … et une requête écrite à la main est refusée
        $formulaire = $page->filter('form[name="role_compte"]')->form();
        $formulaire->disableValidation();
        $formulaire['role_compte[role]'] = 'ROLE_ADMIN';
        $this->client->submit($formulaire);

        self::assertSame('ROLE_USER', $this->compteEnBase('claire.martin@latournelle.example')['role']);
        self::assertNotContains('ROLE_CHANGED', $this->actionsDuJournal());

        // La règle est aussi dans le service, quel que soit l'écran qui l'appelle
        $this->expectException(RegleCompteException::class);
        $this->expectExceptionMessage('admin.rule.no_promotion');
        $this->gestion()->changerRole($this->adminRecharge(), $this->recharger($compte), Role::Admin);
    }

    public function testUnAdminNePeutPasSeDesactiverLuiMeme(): void
    {
        // Pas de bouton « Désactiver » sur sa propre ligne
        $page = $this->client->request('GET', '/fr/admin/comptes');
        self::assertCount(0, $page->filter(sprintf('tr[data-compte="%d"] form[action$="/desactiver"]', $this->admin->getId())));

        // Requête écrite à la main, avec un jeton CSRF valable : refusée par la règle, pas par l'interface
        $this->client->request('POST', sprintf('/fr/admin/comptes/%d/desactiver', $this->admin->getId()), [
            '_csrf_token' => $this->jetonCsrf('compte-'.$this->admin->getId()),
        ]);
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Vous ne pouvez pas désactiver votre propre compte.', $page->filter('.alert-danger')->text());
        self::assertSame(1, (int) $this->compteEnBase(self::ADMIN)['actif']);
        self::assertNotContains('USER_DISABLED', $this->actionsDuJournal());
    }

    public function testUnAdminNePeutPasRetirerSonPropreRoleAdmin(): void
    {
        $page = $this->client->request('GET', sprintf('/fr/admin/comptes/%d/modifier', $this->admin->getId()));
        $this->client->submit($page->filter('form[name="role_compte"]')->form(['role_compte[role]' => 'ROLE_USER']));
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Vous ne pouvez pas retirer votre propre rôle administrateur.', $page->filter('.alert-danger')->text());
        self::assertSame('ROLE_ADMIN', $this->compteEnBase(self::ADMIN)['role']);
    }

    public function testIlResteToujoursAuMoinsUnAdminActif(): void
    {
        $regles = static::getContainer()->get(ReglesComptes::class);
        [$autre] = $this->creerAdminEnrole('bruno.admin+admin@latournelle.example', $this->motDePasseAleatoire(), 'Admin', 'Bruno');

        // Deux administrateurs actifs : l'un peut désactiver l'autre ou lui retirer son rôle
        self::assertNull($regles->refusDesactivation($this->adminRecharge(), $this->recharger($autre)));
        self::assertNull($regles->refusChangementDeRole($this->adminRecharge(), $this->recharger($autre), Role::User));

        // Un seul administrateur actif : il est protégé, même si la demande vient d'un compte qui n'est plus actif
        $this->connexionSql()->executeStatement('UPDATE utilisateur SET actif = 0 WHERE email = ?', [self::ADMIN]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $acteur = $this->adminRecharge();
        $dernier = $this->recharger($autre);

        self::assertSame('admin.rule.last_admin', $regles->refusDesactivation($acteur, $dernier));
        self::assertSame('admin.rule.last_admin', $regles->refusChangementDeRole($acteur, $dernier, Role::User));
    }

    public function testDesactiverPuisReactiverUnCompte(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $compte = $this->creerUtilisateur('claire.martin@latournelle.example', $motDePasse);
        $ligne = sprintf('tr[data-compte="%d"]', $compte->getId());

        // Désactivation depuis le tableau
        $page = $this->client->request('GET', '/fr/admin/comptes');
        $this->client->submit($page->filter($ligne.' form[action$="/desactiver"]')->form());
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Il n\'est pas supprimé', $page->filter('.alert-success')->text());
        self::assertStringContainsString('Désactivé', $page->filter($ligne)->text());
        // Le compte est toujours en base : désactivé, pas supprimé
        self::assertSame(0, (int) $this->compteEnBase('claire.martin@latournelle.example')['actif']);
        $desactivation = $this->derniereAction('USER_DISABLED');
        self::assertEquals($this->admin->getId(), $desactivation['id_acteur']);
        self::assertEquals($compte->getId(), $desactivation['cible_id']);

        // Réactivation
        $this->client->submit($page->filter($ligne.' form[action$="/reactiver"]')->form());
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Actif', $page->filter($ligne)->text());
        self::assertSame(1, (int) $this->compteEnBase('claire.martin@latournelle.example')['actif']);
        self::assertEquals($compte->getId(), $this->derniereAction('USER_ENABLED')['cible_id']);
    }

    public function testUnCompteDesactiveNePeutPlusSeConnecter(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $compte = $this->creerUtilisateur('claire.martin@latournelle.example', $motDePasse);
        $this->gestion()->desactiver($this->adminRecharge(), $this->recharger($compte));

        // L'administrateur se déconnecte, la personne désactivée essaie d'entrer avec le bon mot de passe
        $this->client->submit($this->client->request('GET', '/fr/admin/comptes')->filter('form[action="/logout"]')->form());
        $page = $this->seConnecter('claire.martin@latournelle.example', $motDePasse);

        self::assertSame(self::MESSAGE_GENERIQUE, $page->filter('.alert-danger')->text());
        self::assertContains('LOGIN_DISABLED', $this->actionsDuJournal());
    }

    public function testReinitialisationDeLaTotpParUnAutreAdmin(): void
    {
        [$autre] = $this->creerAdminEnrole('bruno.admin+admin@latournelle.example', $this->motDePasseAleatoire(), 'Admin', 'Bruno');
        static::getContainer()->get(GestionCodesSecours::class)->generer($autre);

        $page = $this->client->request('GET', '/fr/admin/comptes');
        // Jamais sur son propre compte
        self::assertCount(0, $page->filter(sprintf('tr[data-compte="%d"] form[action$="/2fa/reinitialiser"]', $this->admin->getId())));

        $this->client->submit($page->filter(sprintf('tr[data-compte="%d"] form[action$="/2fa/reinitialiser"]', $autre->getId()))->form());
        self::assertResponseRedirects('/fr/admin/comptes');

        // Secret effacé, codes de secours révoqués (mais pas supprimés) : l'enrôlement sera redemandé
        $ligne = $this->compteEnBase('bruno.admin+admin@latournelle.example');
        self::assertSame(0, (int) $ligne['totp_actif']);
        self::assertNull($ligne['totp_secret']);
        self::assertSame(8, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM code_secours'));
        self::assertSame(0, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM code_secours WHERE date_utilisation IS NULL'));

        $reinitialisation = $this->derniereAction('TOTP_RESET');
        self::assertEquals($this->admin->getId(), $reinitialisation['id_acteur']);
        self::assertEquals($autre->getId(), $reinitialisation['cible_id']);
    }

    public function testRenvoyerLEmailDActivation(): void
    {
        $compte = $this->gestion()->creer($this->adminRecharge(), 'claire.martin@latournelle.example', 'Martin', 'Claire', Role::User);

        $page = $this->client->request('GET', '/fr/admin/comptes');
        $this->client->submit($page->filter(sprintf('tr[data-compte="%d"] form[action$="/activation/renvoyer"]', $compte->getId()))->form());

        self::assertEmailCount(1);
        self::assertResponseRedirects('/fr/admin/comptes');
    }

    public function testUneActionSansJetonCsrfEstRefusee(): void
    {
        $compte = $this->creerUtilisateur('claire.martin@latournelle.example', $this->motDePasseAleatoire());

        $this->client->request('POST', sprintf('/fr/admin/comptes/%d/desactiver', $compte->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->compteEnBase('claire.martin@latournelle.example')['actif']);
    }

    public function testLEcranEstDisponibleEnAnglais(): void
    {
        $page = $this->client->request('GET', '/en/admin/comptes');

        self::assertSelectorTextContains('h1', 'User accounts');
        self::assertStringContainsString('No account is ever deleted', $page->filter('.alert-info')->text());
    }

    private function creer(string $prenom, string $nom, string $email, string $role): Crawler
    {
        $page = $this->client->request('GET', '/fr/admin/comptes/creer');

        return $this->client->submit($page->filter('form[name="compte"]')->form([
            'compte[prenom]' => $prenom,
            'compte[nom]' => $nom,
            'compte[email]' => $email,
            'compte[role]' => $role,
            'compte[langue]' => 'fr',
        ]));
    }

    private function gestion(): GestionComptes
    {
        return static::getContainer()->get(GestionComptes::class);
    }

    /** Le compte relu dans le conteneur courant (le noyau redémarre entre deux requêtes du navigateur de test). */
    private function recharger(Utilisateur $compte): Utilisateur
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Utilisateur::class, $compte->getId());
    }

    private function adminRecharge(): Utilisateur
    {
        return $this->recharger($this->admin);
    }

    /** @return array<string, mixed> */
    private function derniereAction(string $action): array
    {
        $lignes = array_values(array_filter($this->journal(), static fn (array $ligne): bool => $action === $ligne['action']));
        self::assertNotEmpty($lignes, sprintf('Action %s absente du journal.', $action));

        return end($lignes);
    }
}
