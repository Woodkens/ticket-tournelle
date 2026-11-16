<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Security\JournalAuditLogger;
use App\Security\Totp\AuthentificateurTotp;
use App\Security\Totp\ChiffreurTotp;
use App\Security\Totp\GestionCodesSecours;
use App\Security\Totp\LimiteurTotp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enrôlement TOTP d'un administrateur (ENF-05, C5, CdC §7.1). Forcé à sa première connexion :
 * tant qu'il n'est pas terminé, EnrolementTotpSubscriber ramène toute autre page ici.
 *
 * 1. le serveur tire un secret et l'enregistre chiffré (la 2FA n'est pas encore active) ;
 * 2. l'administrateur scanne le QR code avec son application d'authentification ;
 * 3. il saisit un premier code : s'il est correct, la 2FA devient active ;
 * 4. les 8 codes de secours sont affichés une seule fois.
 */
final class EnrolementTotpController extends AbstractController
{
    public function __construct(
        private readonly AuthentificateurTotp $authentificateur,
        private readonly ChiffreurTotp $chiffreur,
    ) {
    }

    #[Route('/{_locale}/2fa/enrolement', name: 'app_2fa_enrolement', requirements: ['_locale' => 'fr|en'], methods: ['GET', 'POST'])]
    public function enroler(
        Request $request,
        EntityManagerInterface $entityManager,
        GestionCodesSecours $codesSecours,
        LimiteurTotp $limiteur,
        JournalAuditLogger $journal,
    ): Response {
        $compte = $this->administrateurAEnroler();
        if (null === $compte) {
            return $this->redirectToRoute('app_root'); // déjà enrôlé : rien à faire ici
        }

        // Étape 1 : un secret par compte, gardé chiffré jusqu'à la fin de l'enrôlement
        if (null === $compte->getTotpSecret()) {
            $compte->preparerTotp($this->chiffreur->chiffrer($this->authentificateur->generateSecret()));
            $entityManager->flush();
        }

        $erreur = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('enrolement-totp', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            // Étape 3 : le premier code prouve que le téléphone a bien enregistré le secret
            if ($this->authentificateur->checkCode($compte, (string) $request->request->get('code'))) {
                $compte->activerTotp();
                $entityManager->flush();
                $limiteur->reinitialiser($compte);
                $journal->tracerCompte(ActionAudit::TotpEnrole, $compte, $compte);

                // Étape 4 : les codes de secours ne seront plus jamais affichés
                return $this->sansCache($this->render('security/2fa_codes_secours.html.twig', [
                    'codes' => $codesSecours->generer($compte),
                ]));
            }

            $limiteur->noterEchec($compte);
            $journal->tracerCompte(ActionAudit::TotpEchoue, $compte, $compte, 'enrôlement');
            $erreur = true;
        }

        return $this->sansCache($this->render('security/2fa_enrolement.html.twig', [
            // Secret montré par groupes de 4 pour la saisie manuelle, si le QR code ne peut pas être scanné
            'secret' => implode(' ', str_split($this->chiffreur->dechiffrer((string) $compte->getTotpSecret()), 4)),
            'erreur' => $erreur,
        ]));
    }

    /**
     * Le QR code est dessiné par le serveur (aucun service extérieur ne voit le secret) et servi comme une image :
     * le gabarit n'a donc pas besoin de |raw.
     */
    #[Route('/{_locale}/2fa/qr.svg', name: 'app_2fa_qr', requirements: ['_locale' => 'fr|en'], methods: ['GET'])]
    public function qrCode(): Response
    {
        $compte = $this->administrateurAEnroler();
        if (null === $compte || null === $compte->getTotpSecret()) {
            throw $this->createNotFoundException();
        }

        $dessinateur = new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd()));

        return $this->sansCache(new Response(
            $dessinateur->writeString($this->authentificateur->getQRContent($compte)),
            Response::HTTP_OK,
            ['Content-Type' => 'image/svg+xml', 'X-Content-Type-Options' => 'nosniff'],
        ));
    }

    /** Le compte connecté, seulement si c'est un administrateur qui n'a pas terminé son enrôlement. */
    private function administrateurAEnroler(): ?Utilisateur
    {
        $compte = $this->getUser();
        if (!$compte instanceof Utilisateur || !$compte->estAdmin()) {
            throw $this->createAccessDeniedException();
        }

        return $compte->doitEnrolerTotp() ? $compte : null;
    }

    /** Secret, QR code et codes de secours ne doivent rester ni dans le cache du navigateur ni dans un proxy. */
    private function sansCache(Response $reponse): Response
    {
        // Sans cet en-tête interne, Symfony remplacerait notre réglage dès qu'une session est utilisée
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
