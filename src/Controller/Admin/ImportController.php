<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Form\ImportPersonnelsType;
use App\Import\ImportPersonnels;
use App\Security\Compte\GestionComptes;
use App\Security\JournalAuditLogger;
use App\Security\Voter\AccesAdminVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import CSV des personnels (C1, H3) en deux temps :
 *  1. envoi du fichier -> APERÇU avec les erreurs ligne par ligne, rien n'est écrit ;
 *  2. confirmation -> création de tous les comptes, ou d'aucun (« tout ou rien », D-25).
 */
#[Route('/{_locale}/admin/comptes/import', requirements: ['_locale' => 'fr|en'])]
#[IsGranted(AccesAdminVoter::ACCES_ADMIN)]
final class ImportController extends AbstractController
{
    /** Les lignes vérifiées attendent ici, dans la session de l'administrateur, entre l'aperçu et la confirmation. */
    private const CLE_SESSION = 'import_personnels';

    #[Route('', name: 'app_admin_import', methods: ['GET', 'POST'])]
    public function envoyer(Request $request, ImportPersonnels $import): Response
    {
        $formulaire = $this->createForm(ImportPersonnelsType::class);
        $formulaire->handleRequest($request);
        $request->getSession()->remove(self::CLE_SESSION);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var UploadedFile $fichier */
            $fichier = $formulaire->get('fichier')->getData();
            $rapport = $import->analyserFichier($fichier->getContent());

            if ($rapport->estValide()) {
                $request->getSession()->set(self::CLE_SESSION, $rapport->enTableau());
            }

            return $this->render('admin/comptes/import_apercu.html.twig', ['rapport' => $rapport]);
        }

        return $this->render('admin/comptes/import.html.twig', ['formulaire' => $formulaire]);
    }

    #[Route('/confirmer', name: 'app_admin_import_confirmer', methods: ['POST'])]
    public function confirmer(
        Request $request,
        ImportPersonnels $import,
        GestionComptes $comptes,
        EntityManagerInterface $entityManager,
        JournalAuditLogger $journal,
    ): Response {
        if (!$this->isCsrfTokenValid('import-confirmer', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $lignes = $request->getSession()->remove(self::CLE_SESSION);
        if (!\is_array($lignes) || [] === $lignes) {
            return $this->redirectToRoute('app_admin_import');
        }

        // Seconde vérification, juste avant d'écrire : un compte a pu être créé entre-temps
        $rapport = $import->verifier($lignes);
        if (!$rapport->estValide()) {
            return $this->render('admin/comptes/import_apercu.html.twig', ['rapport' => $rapport]);
        }

        $acteur = $this->getUser();
        \assert($acteur instanceof Utilisateur);

        // Une seule transaction : si une création échoue, AUCUN compte n'est créé
        $crees = $entityManager->wrapInTransaction(function () use ($rapport, $comptes, $acteur, $journal): array {
            $crees = [];
            foreach ($rapport->lignes as $ligne) {
                \assert(null !== $ligne->role);
                $crees[] = $comptes->creer($acteur, $ligne->email, $ligne->nom, $ligne->prenom, $ligne->role, origine: 'import');
            }
            $journal->tracer(ActionAudit::ImportComptes, $acteur, null, null, sprintf('%d comptes créés', \count($crees)));

            return $crees;
        });

        // Les e-mails d'activation partent après l'enregistrement : chacun choisira son mot de passe
        foreach ($crees as $compte) {
            $comptes->envoyerActivation($acteur, $compte);
        }

        $this->addFlash('success', ['cle' => 'admin.flash.imported', 'params' => ['%nombre%' => (string) \count($crees)]]);

        return $this->redirectToRoute('app_admin_comptes');
    }
}
