<?php

namespace App\Form;

use App\Import\ImportPersonnels;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/** Envoi du fichier CSV des personnels : taille limitée, type vérifié côté serveur. */
final class ImportPersonnelsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fichier', FileType::class, [
            'label' => 'import.file',
            'help' => 'import.file_help',
            'attr' => ['accept' => '.csv,text/csv'],
            'constraints' => [
                new NotNull(),
                new File(
                    maxSize: ImportPersonnels::TAILLE_MAX,
                    // Extension .csv ET contenu de type texte (vérifié par le serveur, pas seulement le nom du fichier)
                    extensions: ['csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']],
                ),
            ],
        ]);
    }
}
