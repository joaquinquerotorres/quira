<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfessionalProfile;
use App\Entity\User;

/**
 * Fuente de verdad operativa para permisos de plan de pago (PRO/SOLVER).
 *
 * Regla explícita paidThroughAt === null: no hay ventana de suscripción conocida →
 * se trata como sin pago vigente (límites FREE, sin HIGH nuevo). Tras checkout/webhooks
 * debe quedar siempre fijada; cuentas legacy sin fecha quedan alineadas con FREE en servidor.
 */
final class ProfessionalSubscriptionService
{
    public function hasActivePaidSubscription(?ProfessionalProfile $profile): bool
    {
        if ($profile === null) {
            return false;
        }

        $paidThroughAt = $profile->getPaidThroughAt();
        if ($paidThroughAt === null) {
            return false;
        }

        return $paidThroughAt > new \DateTimeImmutable('now');
    }

    /**
     * Límites mensuales de puja y restricciones HIGH para quien no tiene suscripción activa.
     */
    public function isSubjectToFreeProfessionalLimits(User $user): bool
    {
        return !$this->hasActivePaidSubscription($user->getProfessionalProfile());
    }
}
