<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * ENF-01 : les mots de passe sont hachés en Argon2id, avec un sel unique.
 */
final class PasswordHasherTest extends KernelTestCase
{
    public function testLeHachageUtiliseArgon2idAvecUnSelUnique(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(PasswordHasherFactoryInterface::class);
        $hasher = $factory->getPasswordHasher(PasswordAuthenticatedUserInterface::class);

        $empreinte1 = $hasher->hash('mot-de-passe-de-test-123');
        $empreinte2 = $hasher->hash('mot-de-passe-de-test-123');

        self::assertStringStartsWith('$argon2id$', $empreinte1);
        self::assertNotSame($empreinte1, $empreinte2, 'Le sel doit rendre chaque empreinte unique.');
        self::assertTrue($hasher->verify($empreinte1, 'mot-de-passe-de-test-123'));
        self::assertFalse($hasher->verify($empreinte1, 'mauvais-mot-de-passe'));
    }
}
