<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formulaire « Mot de passe oublié » : une adresse e-mail, et le jeton CSRF ajouté par Symfony. */
final class MotDePasseOublieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'login.email',
            'attr' => ['autocomplete' => 'username', 'maxlength' => 180],
            'constraints' => [new NotBlank(), new Email(), new Length(max: 180)],
        ]);
    }
}
