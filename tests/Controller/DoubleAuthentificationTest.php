<?php

namespace App\Tests\Controller;

use App\Enum\Role;
use App\Security\Totp\ChiffreurTotp;
use App\Security\Totp\GestionCodesSecours;
use App\Tests\Support\FonctionnelTestCase;

/**
 * Comptes administrateurs (C5, ENF-05) : TOTP obligatoire, enrôlement forcé, codes de secours,
 * anti-rejeu, limite d'essais, et accès à /admin réservé aux administrateurs dont la 2FA est validée.
 */
final class DoubleAuthentificationTest extends FonctionnelTestCase
{
    private const ADMIN = 'alice.admin+admin@latournelle.example';

    public function testUnAdminSansCodeTotpNAPasAccesALAdministration(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerAdminEnrole(self::ADMIN, $motDePasse);

        // Mot de passe correct, mais le code TOTP n'a pas encore été saisi
        $this->seConnecter(self::ADMIN, $motDePasse);

        foreach (['/fr/admin/comptes', '/fr/admin/comptes/creer', '/fr', '/'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseRedirects('https://localhost/2fa', null, sprintf('%s doit renvoyer vers la saisie du code.', $page));
        }

        // Mot de passe seul : rien n'est noté comme une connexion réussie (ni « dernière connexion »)
        self::assertSame([], $this->actionsDuJournal());
    }

    public function testUnCodeTotpValideDonneAccesALAdministration(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [$admin, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);

        $this->seConnecterEnAdmin(self::ADMIN, $motDePasse, $secret);

        $page = $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Comptes utilisateurs');
        self::assertStringContainsString('Alice A. · Admin (2FA active)', $page->filter('header')->text());

        $journal = $this->journal();
        // La connexion n'est notée « réussie » qu'une fois le code TOTP accepté
        self::assertSame(['TOTP_SUCCESS', 'LOGIN_SUCCESS'], array_column($journal, 'action'));
        self::assertEquals($admin->getId(), $journal[1]['id_acteur']);
    }

    public function testUnCodeFauxEstRefuse(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);
        $this->seConnecter(self::ADMIN, $motDePasse);

        $this->saisirCodeTotp($this->codeTotpFaux($secret));
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Code incorrect', $page->filter('.alert-danger')->text());
        $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseRedirects('https://localhost/2fa');
        self::assertContains('TOTP_FAILED', $this->actionsDuJournal());
    }

    public function testUnCodeDejaUtiliseEstRefuse(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);
        $code = $this->codeTotp($secret);

        // Première connexion avec ce code : acceptée
        $this->seConnecter(self::ADMIN, $motDePasse);
        $this->saisirCodeTotp($code);
        $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->getCrawler()->filter('form[action="/logout"]')->form());

        // Quelqu'un qui a vu le code par-dessus l'épaule le rejoue dans la même demi-minute : refusé
        $this->seConnecter(self::ADMIN, $motDePasse);
        $this->saisirCodeTotp($code);
        $this->client->request('GET', '/fr/admin/comptes');

        self::assertResponseRedirects('https://localhost/2fa');
        self::assertContains('TOTP_REPLAYED', $this->actionsDuJournal());
        self::assertNotNull($this->compteEnBase(self::ADMIN)['totp_dernier_creneau']);
    }

    public function testLaSaisieEstBloqueeApresCinqCodesFaux(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);
        $this->seConnecter(self::ADMIN, $motDePasse);

        for ($essai = 1; $essai <= 5; ++$essai) {
            $this->saisirCodeTotp($this->codeTotpFaux($secret));
        }

        // 6e essai avec le BON code : refusé quand même
        $this->saisirCodeTotp($this->codeTotp($secret));
        $this->client->request('GET', '/fr/admin/comptes');

        self::assertResponseRedirects('https://localhost/2fa');
        self::assertSame(5, \count(array_keys($this->actionsDuJournal(), 'TOTP_FAILED', true)));
        self::assertContains('TOTP_BLOCKED', $this->actionsDuJournal());
    }

    public function testUnCodeDeSecoursNeSertQuUneFois(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [$admin] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);
        $codes = static::getContainer()->get(GestionCodesSecours::class)->generer($admin);
        self::assertCount(8, $codes);

        // En base : 8 empreintes Argon2id, aucun code lisible
        $lignes = $this->connexionSql()->fetchAllAssociative('SELECT * FROM code_secours');
        self::assertCount(8, $lignes);
        foreach ($lignes as $ligne) {
            self::assertStringStartsWith('$argon2id$', $ligne['code_hash']);
        }
        self::assertStringNotContainsString(str_replace('-', '', $codes[0]), json_encode($lignes, \JSON_THROW_ON_ERROR));

        // Téléphone perdu : le code de secours remplace le code TOTP
        $this->seConnecter(self::ADMIN, $motDePasse);
        $this->saisirCodeTotp($codes[0]);
        $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseIsSuccessful();

        $journal = $this->journal();
        self::assertContains('BACKUP_CODE_USED', array_column($journal, 'action'));
        // Le journal ne contient ni le code ni un morceau du code
        self::assertStringNotContainsString(substr($codes[0], 0, 4), json_encode($journal, \JSON_THROW_ON_ERROR));

        // Le même code une seconde fois : refusé (aucune ligne supprimée, le code est marqué utilisé)
        $this->client->submit($this->client->getCrawler()->filter('form[action="/logout"]')->form());
        $this->seConnecter(self::ADMIN, $motDePasse);
        $this->saisirCodeTotp($codes[0]);
        $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseRedirects('https://localhost/2fa');
        self::assertSame(8, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM code_secours'));
        self::assertSame(7, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM code_secours WHERE date_utilisation IS NULL'));
    }

    public function testEnrolementForceALaPremiereConnexion(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $this->creerUtilisateur(self::ADMIN, $motDePasse, Role::Admin, nom: 'Admin', prenom: 'Alice');

        // Pas encore de TOTP : quelle que soit la page demandée, retour à l'enrôlement
        $this->seConnecter(self::ADMIN, $motDePasse);
        foreach (['/fr/admin/comptes', '/fr', '/en', '/fr/admin/comptes/import'] as $adresse) {
            $this->client->request('GET', $adresse);
            self::assertResponseRedirects(null, null, sprintf('%s doit renvoyer vers l\'enrôlement.', $adresse));
            self::assertStringContainsString('/2fa/enrolement', (string) $this->client->getResponse()->headers->get('Location'));
        }

        $page = $this->client->request('GET', '/fr/2fa/enrolement');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Activer la double authentification');
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));

        // Le secret est en base, CHIFFRÉ, et la 2FA n'est pas encore active
        $ligne = $this->compteEnBase(self::ADMIN);
        self::assertSame(0, (int) $ligne['totp_actif']);
        $secret = static::getContainer()->get(ChiffreurTotp::class)->dechiffrer($ligne['totp_secret']);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertStringNotContainsString($secret, $ligne['totp_secret']);

        // Le QR code est une image SVG dessinée par le serveur
        $this->client->request('GET', '/fr/2fa/qr.svg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');

        // Un code faux ne valide rien
        $page = $this->client->submit($page->filter('form[action$="/2fa/enrolement"]')->form(['code' => $this->codeTotpFaux($secret)]));
        self::assertStringContainsString('Code incorrect', $page->filter('.alert-danger')->text());
        self::assertSame(0, (int) $this->compteEnBase(self::ADMIN)['totp_actif']);

        // Le bon code active la 2FA et affiche les 8 codes de secours, une seule fois
        $page = $this->client->submit($page->filter('form[action$="/2fa/enrolement"]')->form(['code' => $this->codeTotp($secret)]));
        self::assertResponseIsSuccessful();
        self::assertCount(8, $page->filter('#codes-secours li'));
        self::assertSame(1, (int) $this->compteEnBase(self::ADMIN)['totp_actif']);
        self::assertSame(8, (int) $this->connexionSql()->fetchOne('SELECT COUNT(*) FROM code_secours WHERE date_utilisation IS NULL'));
        self::assertContains('TOTP_ENROLLED', $this->actionsDuJournal());

        // Le journal ne contient ni le secret ni un code
        self::assertStringNotContainsString($secret, json_encode($this->journal(), \JSON_THROW_ON_ERROR));

        // L'administration est maintenant accessible
        $this->client->request('GET', '/fr/admin/comptes');
        self::assertResponseIsSuccessful();
    }

    public function testUnUtilisateurEtUnTechnicienRecoiventUn403SurLAdministration(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        $utilisateur = $this->creerUtilisateur('user@latournelle.example', $motDePasse, Role::User);
        $technicien = $this->creerUtilisateur('tech@latournelle.example', $motDePasse, Role::Technicien);
        $direction = $this->creerUtilisateur('direction@latournelle.example', $motDePasse, Role::Direction);

        foreach ([$utilisateur, $technicien, $direction] as $compte) {
            $this->seConnecter($compte->getEmail(), $motDePasse);

            foreach (['/fr/admin/comptes', '/fr/admin/comptes/creer', '/fr/admin/comptes/import'] as $adresse) {
                $this->client->request('GET', $adresse);
                self::assertResponseStatusCodeSame(403, sprintf('%s : %s doit être interdit.', $compte->getRole()->value, $adresse));
            }

            $this->client->request('GET', '/fr');
            $this->client->submit($this->client->getCrawler()->filter('form[action="/logout"]')->form());
        }

        // ENF-14 : chaque refus est journalisé, avec le compte et la page demandée
        $refus = array_values(array_filter($this->journal(), static fn (array $ligne): bool => 'ACCESS_DENIED' === $ligne['action']));
        self::assertCount(9, $refus);
        self::assertEquals($utilisateur->getId(), $refus[0]['id_acteur']);
        self::assertSame('app_admin_comptes', $refus[0]['detail']);
        self::assertEquals($technicien->getId(), $refus[3]['id_acteur']);
    }

    public function testUnVisiteurNonConnecteEstRenvoyeALaConnexion(): void
    {
        $this->client->request('GET', '/fr/admin/comptes');

        self::assertResponseRedirects('/fr/login');
        self::assertSame([], $this->actionsDuJournal());
    }

    public function testPasDeSeSouvenirDeMoi(): void
    {
        $motDePasse = $this->motDePasseAleatoire();
        [, $secret] = $this->creerAdminEnrole(self::ADMIN, $motDePasse);

        $page = $this->client->request('GET', '/fr/login');
        self::assertCount(0, $page->filter('input[name="_remember_me"]'));

        // Même en le demandant à la main, aucun cookie « se souvenir de moi » ni « appareil de confiance » n'est posé
        $formulaire = $page->filter('form[action$="/login"]')->form(['_username' => self::ADMIN, '_password' => $motDePasse]);
        $this->client->request('POST', $formulaire->getUri(), $formulaire->getPhpValues() + ['_remember_me' => 'on']);
        $this->saisirCodeTotp($this->codeTotp($secret));

        $cookies = array_map(static fn ($cookie): string => $cookie->getName(), $this->client->getCookieJar()->all());
        self::assertSame(['MOCKSESSID'], $cookies);
    }
}
