<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Choix du mot de passe par le titulaire du compte (activation ou réinitialisation).
 * Le mot de passe est saisi deux fois ; la politique ENF-02 est vérifiée par PolitiqueMotDePasse.
 */
final class ChoixMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motDePasse', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'password.mismatch',
            'first_options' => [
                'label' => 'password_choice.new',
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'password_choice.help',
                'help_translation_parameters' => ['%min%' => $options['longueur_min']],
            ],
            'second_options' => [
                'label' => 'password_choice.confirm',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'constraints' => [new NotBlank()],
            // Le mot de passe n'est jamais réaffiché dans le formulaire après une erreur
            'options' => ['always_empty' => true],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['longueur_min' => 12]);
        $resolver->setAllowedTypes('longueur_min', 'int');
    }
}
