<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Admin\AdminStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[Route('/api/admin')]
#[IsGranted(User::ROLE_ADMIN)]
final class AdminStatsController extends AbstractController
{
    public function __construct(
        private readonly AdminStatsService $adminStatsService,
    ) {
    }

    #[Route('/stats/overview', name: 'api_admin_stats_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $from = $request->query->getString('from');
        $to = $request->query->getString('to');
        if ($from === '' || $to === '') {
            throw new BadRequestHttpException('Debes indicar `from` y `to` (YYYY-MM-DD).');
        }

        $payload = $this->adminStatsService->overview($from, $to);

        return $this->json($payload);
    }
}
