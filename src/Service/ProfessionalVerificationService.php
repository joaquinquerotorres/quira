<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfessionalProfile;
use App\Entity\User;

final class ProfessionalVerificationService
{
    /**
     * Recalcula el flag `isVerified` del perfil profesional según:
     * - email verificado
     * - teléfono de PRO verificado
     * - si el usuario es ROLE_PRO: además CIF (verifiedTaxId) verificado
     */
    public function recalculateIsVerified(ProfessionalProfile $profile, User $user): void
    {
        if (!$user->isVerifiedEmail()) {
            $profile->setIsVerified(false);
            return;
        }

        if (!$profile->isVerifiedPhone()) {
            $profile->setIsVerified(false);
            return;
        }

        $roles = $user->getRoles();
        $isProTier = in_array('ROLE_PRO', $roles, true);

        if ($isProTier) {
            $profile->setIsVerified($profile->isVerifiedTaxId());
            return;
        }

        // FREE/SOLVER: basta con email + teléfono verificados
        $profile->setIsVerified(true);
    }
}

