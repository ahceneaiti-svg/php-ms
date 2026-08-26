<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
class UserController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'user_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = array_map(
            static fn (User $user) => $user->toArray(),
            $this->userRepository->findAll()
        );

        return new JsonResponse($users);
    }

    #[Route('/{id}', name: 'user_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            $this->logger->warning('Utilisateur introuvable', ['user_id' => $id]);

            return new JsonResponse(['error' => 'Utilisateur introuvable'], 404);
        }

        return new JsonResponse($user->toArray());
    }

    #[Route('', name: 'user_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        foreach (['email', 'firstName', 'lastName'] as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(['error' => sprintf("Le champ '%s' est requis", $field)], 400);
            }
        }

        $user = new User($data['email'], $data['firstName'], $data['lastName']);
        $this->userRepository->save($user);

        $this->logger->info('Utilisateur cree', ['user_id' => $user->getId(), 'email' => $user->getEmail()]);

        return new JsonResponse($user->toArray(), 201);
    }
}
