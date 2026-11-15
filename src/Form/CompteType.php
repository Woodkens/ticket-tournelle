<?php

namespace App\Form;

use App\Enum\Langue;
use App\Enum\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Création d'un compte par un administrateur (US06). Pas de champ « mot de passe » :
 * c'est le titulaire qui le choisira avec le lien d'activation (ENF-04).
 */
final class CompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'admin.account.first_name',
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('nom', TextType::class, [
                'label' => 'admin.account.last_name',
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'admin.account.email',
                'constraints' => [new NotBlank(), new Email(), new Length(max: 180)],
            ])
            ->add('role', EnumType::class, [
                'class' => Role::class,
                'label' => 'admin.account.role',
                'choice_label' => static fn (Role $role): string => 'role.'.$role->value,
                'help' => 'admin.account.role_help',
            ])
            ->add('langue', EnumType::class, [
                'class' => Langue::class,
                'label' => 'admin.account.language',
                'choice_label' => static fn (Langue $langue): string => 'nav.locale.'.$langue->value,
            ]);
    }
}
