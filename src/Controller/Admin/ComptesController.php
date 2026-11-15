<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\Langue;
use App\Enum\Role;
use App\Form\CompteType;
use App\Form\RoleCompteType;
use App\Repository\UtilisateurRepository;
use App\Security\Compte\GestionComptes;
use App\Security\Compte\RegleCompteException;
use App\Security\Voter\AccesAdminVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration des comptes (maquette W6, US06, C1, C5).
 *
 * Accès : ROLE_ADMIN avec la double authentification validée (règle access_control sur /admin
 * + droit ACCES_ADMIN vérifié ici). Toute autre personne reçoit une erreur 403, journalisée.
 *
 * Il n'y a AUCUNE action de suppression : ni route, ni bouton, ni méthode (CdC §2.1).
 */
#[Route('/{_locale}/admin/comptes', requirements: ['_locale' => 'fr|en'])]
#[IsGranted(AccesAdminVoter::ACCES_ADMIN)]
final class ComptesController extends AbstractController
{
    public function __construct(
        private readonly GestionComptes $comptes,
    ) {
    }

    #[Route('', name: 'app_admin_comptes', methods: ['GET'])]
    public function liste(Request $request, UtilisateurRepository $utilisateurs): Response
    {
        // Filtres lus dans l'adresse ; une valeur inconnue est simplement ignorée
        $recherche = mb_substr(trim($request->query->getString('q')), 0, 100);
        $role = Role::tryFrom($request->query->getString('role'));
        $etat = $request->query->getString('etat');
        $actif = match ($etat) {
            'actif' => true,
            'desactive' => false,
            default => null,
        };
        $page = max(1, $request->query->getInt('page', 1));

        $resultats = $utilisateurs->rechercher($recherche, $role, $actif, $page);
        $comptes = iterator_to_array($resultats);

        return $this->render('admin/comptes/liste.html.twig', [
            'comptes' => $comptes,
            'total' => \count($resultats),
            'page' => $page,
            'pages' => max(1, (int) ceil(\count($resultats) / UtilisateurRepository::PAR_PAGE)),
            'filtres' => ['q' => $recherche, 'role' => $role?->value ?? '', 'etat' => null === $actif ? '' : $etat],
            'roles' => Role::cases(),
            'dernieres_connexions' => $utilisateurs->dernieresConnexions(
                array_map(static fn (Utilisateur $compte): int => (int) $compte->getId(), $comptes),
            ),
        ]);
    }

    #[Route('/creer', name: 'app_admin_compte_creer', methods: ['GET', 'POST'])]
    public function creer(Request $request, TranslatorInterface $translator): Response
    {
        $formulaire = $this->createForm(CompteType::class, ['role' => Role::User, 'langue' => Langue::Fr]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $saisie = $formulaire->getData();

            try {
                $compte = $this->comptes->creer(
                    $this->acteur(),
                    $saisie['email'],
                    $saisie['nom'],
                    $saisie['prenom'],
                    $saisie['role'],
                    $saisie['langue'],
                );
                // Le titulaire reçoit un lien pour choisir lui-même son mot de passe (US06)
                $this->comptes->envoyerActivation($this->acteur(), $compte);

                $this->addFlash('success', 'admin.flash.created');

                return $this->redirectToRoute('app_admin_comptes');
            } catch (RegleCompteException $refus) {
                $formulaire->get('email')->addError(
                    new FormError($translator->trans($refus->getMessage(), $refus->parametres, 'validators')),
                );
            }
        }

        return $this->render('admin/comptes/creer.html.twig', ['formulaire' => $formulaire]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_compte_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Utilisateur $compte, Request $request): Response
    {
        // Un compte administrateur s'ouvre sur « Utilisateur » : le seul changement possible est de lui retirer son rôle
        $formulaire = $this->createForm(RoleCompteType::class, ['role' => $compte->estAdmin() ? Role::User : $compte->getRole()]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $this->comptes->changerRole($this->acteur(), $compte, $formulaire->get('role')->getData());
                $this->addFlash('success', 'admin.flash.role_changed');

                return $this->redirectToRoute('app_admin_comptes');
            } catch (RegleCompteException $refus) {
                $this->addFlash('error', $refus->getMessage());

                return $this->redirectToRoute('app_admin_compte_modifier', ['id' => $compte->getId()]);
            }
        }

        return $this->render('admin/comptes/modifier.html.twig', ['compte' => $compte, 'formulaire' => $formulaire]);
    }

    #[Route('/{id}/desactiver', name: 'app_admin_compte_desactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function desactiver(Utilisateur $compte, Request $request): Response
    {
        return $this->action($compte, $request, 'admin.flash.disabled', fn () => $this->comptes->desactiver($this->acteur(), $compte));
    }

    #[Route('/{id}/reactiver', name: 'app_admin_compte_reactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reactiver(Utilisateur $compte, Request $request): Response
    {
        return $this->action($compte, $request, 'admin.flash.enabled', fn () => $this->comptes->reactiver($this->acteur(), $compte));
    }

    #[Route('/{id}/2fa/reinitialiser', name: 'app_admin_compte_reinitialiser_2fa', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reinitialiserTotp(Utilisateur $compte, Request $request): Response
    {
        return $this->action($compte, $request, 'admin.flash.totp_reset', fn () => $this->comptes->reinitialiserTotp($this->acteur(), $compte));
    }

    #[Route('/{id}/activation/renvoyer', name: 'app_admin_compte_renvoyer_activation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renvoyerActivation(Utilisateur $compte, Request $request): Response
    {
        return $this->action($compte, $request, 'admin.flash.activation_sent', fn () => $this->comptes->envoyerActivation($this->acteur(), $compte));
    }

    /**
     * Déroulé commun des actions du tableau : uniquement en POST, avec un jeton CSRF propre au compte visé.
     * Une règle non respectée (se désactiver soi-même, dernier admin actif…) donne un message, pas une modification.
     */
    private function action(Utilisateur $compte, Request $request, string $messageDeSucces, callable $faire): Response
    {
        if (!$this->isCsrfTokenValid('compte-'.$compte->getId(), (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $faire();
            $this->addFlash('success', $messageDeSucces);
        } catch (RegleCompteException $refus) {
            $this->addFlash('error', $refus->getMessage());
        }

        return $this->redirectToRoute('app_admin_comptes');
    }

    private function acteur(): Utilisateur
    {
        $acteur = $this->getUser();
        if (!$acteur instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }

        return $acteur;
    }
}
