<?php

namespace App\Mail;

use App\Entity\JetonCompte;
use App\Enum\TypeJeton;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * E-mails liés au compte : lien d'activation (US06) et lien de réinitialisation (US10).
 *
 * - Écrits dans la langue du profil du destinataire (C2), en HTML et en texte brut.
 * - Contenu minimal (R1 §2) : un lien et sa durée de validité, aucun mot de passe, aucune donnée sensible.
 * - En développement, MAILER_DSN=null://null : rien ne part, l'e-mail se lit dans le profiler Symfony.
 */
final class CompteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly string $expediteur,
    ) {
    }

    /**
     * @param string $jetonClair le jeton tel qu'il part dans le lien ; il n'existe nulle part ailleurs
     *                           (la base ne garde que son empreinte)
     */
    public function envoyerLien(JetonCompte $jeton, #[\SensitiveParameter] string $jetonClair): void
    {
        $destinataire = $jeton->getUtilisateur();
        $langue = $destinataire->getLangue()->value;
        $cle = TypeJeton::Activation === $jeton->getType() ? 'email.activation' : 'email.reset';

        $lien = $this->urlGenerator->generate(
            'app_compte_mot_de_passe',
            ['_locale' => $langue, 'jeton' => $jetonClair],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->expediteur, $this->translator->trans('app.name', [], 'messages', $langue)))
            ->to(new Address($destinataire->getEmail(), $destinataire->getPrenom().' '.$destinataire->getNom()))
            ->subject($this->translator->trans($cle.'.subject', [], 'messages', $langue))
            ->locale($langue)
            ->htmlTemplate('emails/compte_lien.html.twig')
            ->textTemplate('emails/compte_lien.txt.twig')
            ->context([
                'cle' => $cle,
                'prenom' => $destinataire->getPrenom(),
                'lien' => $lien,
                'expiration' => $jeton->getDateExpiration(),
            ]);

        $this->mailer->send($email);
    }
}
