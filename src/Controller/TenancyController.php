<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\TenancyRegistryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER')]
final class TenancyController
{
    public function __construct(
        private TenancyRegistryService $tenancyRegistryService,
    ) {}

    #[Route('/tenancies', name: 'controleonline_tenancies_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return new JsonResponse([
            'items' => $this->tenancyRegistryService->list([
                'search' => $request->query->get('search', ''),
                'instalation_status' => $request->query->get('instalation_status', ''),
            ]),
        ]);
    }

    #[Route('/tenancies', name: 'controleonline_tenancies_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->tenancyRegistryService->discover($this->decodePayload($request)),
            201
        );
    }

    #[Route('/tenancies/{id}', name: 'controleonline_tenancies_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->tenancyRegistryService->update($id, $this->decodePayload($request))
        );
    }

    #[Route('/tenancies/{id}/install', name: 'controleonline_tenancies_install', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function install(int $id): JsonResponse
    {
        return new JsonResponse($this->tenancyRegistryService->markPending($id));
    }

    private function decodePayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}
