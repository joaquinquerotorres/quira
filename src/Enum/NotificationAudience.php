<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Indica si la notificación se envía al usuario en su faceta de cliente (sus solicitudes, ofertas recibidas…)
 * o de profesional (oportunidades, ofertas aceptadas…). Así NOTIFICATIONS_CLIENT y NOTIFICATIONS_FREE/PRO/SOLVER
 * pueden aplicarse al mismo User sin mezclar canales.
 */
enum NotificationAudience: string
{
    case Client = 'client';
    case Professional = 'professional';
}
