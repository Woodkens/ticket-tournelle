<?php

namespace App\Controller;

use App\Enum\ActionAudit;
use App\Enum\TypeJeton;
use App\Form\ChoixMotDePasseType;
use App\Form\MotDePasseOublieType;
use App\Mail\CompteMailer;
use App\Repository\UtilisateurRepository;
use App\Security\Compte\GestionJetons;
use App\Security\Compte\PolitiqueMotDePasse;
use App\Security\JournalAuditLogger;
use App\Service\TachesApresReponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pages publiques liées au compte : choisir son mot de passe avec un lien reçu par e-mail
 * (activation ou réinitialisation) et « Mot de passe oublié ». ENF-04, US06, US10.
 */
final class CompteController extends AbstractController
{
    /**
     * Le lien de l'e-mail arrive ici. Aucun mot de passe provisoire ne circule :
     * c'est le titulaire qui choisit son mot de passe, une seule fois par lien.
     */
    #[Route(
        '/{_locale}/compte/mot-de-passe/{jeton}',
        name: 'app_compte_mot_de_passe',
        requirements: ['_locale' => 'fr|en', 'jeton' => '[A-Za-z0-9_-]{43}'],
        methods: ['GET', 'POST'],
    )]
    public function choisirMotDePasse(
        string $jeton,
        Request $request,
        GestionJetons $jetons,
        PolitiqueMotDePasse $politique,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $entityManager,
        JournalAuditLogger $journal,
        TranslatorInterface $translator,
    ): Response {
        $lien = $jetons->trouverUtilisable($jeton);

        // Lien inconnu, déjà utilisé, annulé ou expiré : une seule et même page, sans préciser la raison
        if (null === $lien) {
            return $this->render('compte/lien_invalide.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $compte = $lien->getUtilisateur();
        $activation = TypeJeton::Activation === $lien->getType();

        $formulaire = $this->createForm(ChoixMotDePasseType::class, null, [
            'longueur_min' => $politique->longueurMinimale($compte),
        ]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $motDePasse = (string) $formulaire->get('motDePasse')->getData();

            foreach ($politique->verifier($motDePasse, $compte) as $erreur) {
                $formulaire->get('motDePasse')->get('first')->addError(new FormError($erreur->trans($translator)));
            }

            if ($formulaire->isValid()) {
                // Seule l'empreinte Argon2id est enregistrée (ENF-01)
                $compte->setMotDePasseHash($hasher->hashPassword($compte, $motDePasse));
                $compte->setDoitChangerMdp(false);
                $entityManager->flush();
                $jetons->consommer($lien);

                $journal->tracerCompte(
                    $activation ? ActionAudit::CompteActive : ActionAudit::ReinitialisationFaite,
                    $compte,
                    $compte,
                );

                $this->addFlash('success', $activation ? 'flash.account_activated' : 'flash.password_changed');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('compte/choix_mot_de_passe.html.twig', [
            'formulaire' => $formulaire,
            'activation' => $activation,
        ]);
    }

    /**
     * « Mot de passe oublié » : la réponse est strictement la même que le compte existe ou non,
     * et elle arrive aussi vite dans les deux cas (le lien et l'e-mail sont préparés après la réponse).
     */
    #[Route('/{_locale}/mot-de-passe-oublie', name: 'app_mdp_oublie', requirements: ['_locale' => 'fr|en'], methods: ['GET', 'POST'])]
    public function motDePasseOublie(
        Request $request,
        UtilisateurRepository $utilisateurs,
        GestionJetons $jetons,
        CompteMailer $mailer,
        JournalAuditLogger $journal,
        TachesApresReponse $apresReponse,
        #[Autowire(service: 'limiter.mot_de_passe_oublie')]
        RateLimiterFactory $limiteur,
    ): Response {
        $formulaire = $this->createForm(MotDePasseOublieType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $compte = $utilisateurs->findOneByEmail((string) $formulaire->get('email')->getData());

            // 3 demandes par adresse IP sur 15 minutes : au-delà, rien n'est envoyé
            $autorise = $limiteur->create($request->getClientIp())->consume()->isAccepted();

            // Compte inconnu : id_acteur reste NULL et l'e-mail saisi n'est pas enregistré
            $journal->tracer(
                ActionAudit::ReinitialisationDemandee,
                $compte,
                null !== $compte ? 'utilisateur' : null,
                $compte?->getId(),
                $autorise ? null : 'limite atteinte',
            );

            if ($autorise && null !== $compte && $compte->isActif()) {
                // Compte jamais activé : on renvoie un lien d'activation plutôt qu'un lien de réinitialisation
                $type = $compte->doitChangerMdp() ? TypeJeton::Activation : TypeJeton::Reinitialisation;

                $apresReponse->planifier(static function () use ($jetons, $mailer, $compte, $type): void {
                    [$lien, $clair] = $jetons->creer($compte, $type);
                    $mailer->envoyerLien($lien, $clair);
                });
            }

            return $this->redirectToRoute('app_mdp_oublie_envoye');
        }

        return $this->render('compte/mot_de_passe_oublie.html.twig', ['formulaire' => $formulaire]);
    }

    #[Route('/{_locale}/mot-de-passe-oublie/envoye', name: 'app_mdp_oublie_envoye', requirements: ['_locale' => 'fr|en'], methods: ['GET'])]
    public function motDePasseOublieEnvoye(): Response
    {
        return $this->render('compte/mot_de_passe_oublie_envoye.html.twig');
    }
}
