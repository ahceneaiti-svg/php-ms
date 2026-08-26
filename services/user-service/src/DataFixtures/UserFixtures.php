<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

/**
 * Genere des utilisateurs de demo.
 *
 * docker compose exec user-service php bin/console doctrine:fixtures:load --no-interaction
 */
class UserFixtures extends Fixture
{
    public const USER_COUNT = 20;

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        for ($i = 0; $i < self::USER_COUNT; $i++) {
            $user = new User(
                $faker->unique()->safeEmail(),
                $faker->firstName(),
                $faker->lastName(),
            );

            $manager->persist($user);
        }

        $manager->flush();
    }
}
