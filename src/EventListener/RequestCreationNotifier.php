<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Request;
use App\Enum\NotificationAudience;
use App\Enum\RiskLevel;
use App\Repository\ProfessionalProfileRepository;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Request::class)]
final class RequestCreationNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ProfessionalProfileRepository $proRepository,
        private readonly NotificationService $notificationService
    ) {
    }

    public function postPersist(Request $request, LifecycleEventArgs $event): void
    {
        $matchingPros = $this->proRepository->findMatchingPros($request);
        
        $count = count($matchingPros);
        $category = $request->getCategory()->value;

        if ($count === 0) {
            $this->logger->warning("⚠️ NUEVA SOLICITUD: No hay profesionales calificados disponibles para la categoría {$category}");
            return;
        }

        $this->logger->info("📣 NUEVA SOLICITUD: Notificando {$count} profesionales en la categoría {$category}...");

        $prefix = ($request->getRiskLevel() === RiskLevel::HIGH) ? '⚠️ URGENTE: ' : '🔔 ';
        foreach ($matchingPros as $proProfile) {
            if ($proProfile->getNotifyRequestActivity() === false) {
                $this->logger->info("🔕 El profesional {$proProfile->getFullName()} ha desactivado las notificaciones de nuevas solicitudes. Saltando...");
                continue;
            
            }
            $user = $proProfile->getUser();
            if (null === $user) {
                $this->logger->error("❌ Error: El profesional con ID {$proProfile->getFullName()} no tiene un usuario asociado.");
                continue;
            }

            [$title, $price, $category] = [
                $request->getTitle() ?  'Nueva oportunidad: ' . $request->getTitle() : 'Nueva oportunidad',
                (function () use ($request): string {
                    $min = $request->getEstimatedPriceMin();
                    $max = $request->getEstimatedPriceMax();
                    if ($min === null || $max === null) {
                        return 'Precio no definido';
                    }

                    // estimated_price_* se guarda en céntimos (enteros). Convertimos para el mensaje.
                    $minEuros = number_format($min / 100, 2, '.', '');
                    $maxEuros = number_format($max / 100, 2, '.', '');
                    return "{$minEuros}€ - {$maxEuros}€";
                })(),
                $request->getCategory()->value ?? 'Categoría no definida'
            ];
            
            try {
                $this->logger->info("🔔 Notificando al profesional {$user->getUserIdentifier()} sobre la nueva solicitud '{$request->getTitle()}' en la categoría {$category} con precio {$price}.");
                $this->notificationService->send(
                    $user,
                    $prefix . $title,
                    $prefix . "Hola {$proProfile->getFullName()}, hay un nuevo trabajo de {$category} cerca de ti por {$price}. ¡Echa un vistazo!",
                    'NEW_REQUEST',
                    NotificationAudience::Professional,
                    $request->getId(),
                    [
                        'requestTitle' => $request->getTitle() ?? 'Nueva solicitud',
                        'category' => $category,
                        'priceRange' => $price,
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->error("❌ Error notificando al profesional {$user->getUserIdentifier()}: " . $e->getMessage());
            }
        }
    }
}