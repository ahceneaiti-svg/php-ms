<?php

namespace App\Controller;

use App\Entity\Client;
use App\Repository\ClientRepository;
use App\Service\UserServiceClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/clients')]
class ClientController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly UserServiceClient $userServiceClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'client_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $clients = array_map(
            static fn (Client $client) => $client->toArray(),
            $this->clientRepository->findAll()
        );

        return new JsonResponse($clients);
    }

    /**
     * Recupere un client et enrichit la reponse avec les infos de
     * l'utilisateur associe, en appelant user-service.
     */
    #[Route('/{id}', name: 'client_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $client = $this->clientRepository->find($id);

        if (!$client) {
            return new JsonResponse(['error' => 'Client introuvable'], 404);
        }

        $user = $this->userServiceClient->getUser($client->getUserId());

        if ($user === null) {
            $this->logger->warning('Utilisateur associe introuvable dans user-service', [
                'client_id' => $id,
                'user_id' => $client->getUserId(),
            ]);
        }

        return new JsonResponse([
            ...$client->toArray(),
            'user' => $user,
        ]);
    }

    #[Route('', name: 'client_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['companyName']) || empty($data['userId'])) {
            return new JsonResponse(['error' => "Les champs 'companyName' et 'userId' sont requis"], 400);
        }

        $userId = (int) $data['userId'];

        // On verifie que l'utilisateur existe bien cote user-service avant de creer le client.
        $user = $this->userServiceClient->getUser($userId);
        if ($user === null) {
            return new JsonResponse(['error' => "Aucun utilisateur avec l'id {$userId}"], 422);
        }

        $client = new Client($data['companyName'], $userId);
        $this->clientRepository->save($client);

        $this->logger->info('Client cree', ['client_id' => $client->getId(), 'user_id' => $userId]);

        return new JsonResponse([
            ...$client->toArray(),
            'user' => $user,
        ], 201);
    }
}
