<?php

namespace App\Form;

use App\Enum\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Changement de rôle d'un compte existant. ROLE_ADMIN n'est pas proposé : on ne « promeut » pas
 * un compte en administrateur, on crée un compte admin dédié (C5, D-19).
 */
final class RoleCompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('role', EnumType::class, [
            'class' => Role::class,
            'label' => 'admin.account.role',
            'choices' => [Role::User, Role::Technicien, Role::Direction],
            'choice_label' => static fn (Role $role): string => 'role.'.$role->value,
        ]);
    }
}
