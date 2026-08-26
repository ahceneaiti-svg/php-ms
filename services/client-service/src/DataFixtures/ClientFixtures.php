<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Service\UserServiceClient;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

/**
 * Genere des clients de demo, rattaches a des utilisateurs reels recuperes
 * via l'API de user-service (pas de FK SQL possible, bases separees).
 *
 * Necessite que user-service soit demarre et deja peuple :
 *   docker compose exec user-service php bin/console doctrine:fixtures:load --no-interaction
 *   docker compose exec client-service php bin/console doctrine:fixtures:load --no-interaction
 */
class ClientFixtures extends Fixture
{
    public const CLIENT_COUNT = 30;

    public function __construct(
        private readonly UserServiceClient $userServiceClient,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = $this->userServiceClient->listUsers();

        if (empty($users)) {
            throw new \RuntimeException(
                "Aucun utilisateur disponible dans user-service. Chargez d'abord ses fixtures : ".
                'docker compose exec user-service php bin/console doctrine:fixtures:load --no-interaction'
            );
        }

        $faker = Factory::create('fr_FR');

        for ($i = 0; $i < self::CLIENT_COUNT; $i++) {
            $user = $faker->randomElement($users);
            $client = new Client($faker->company(), (int) $user['id']);
            $manager->persist($client);
        }

        $manager->flush();
    }
}
