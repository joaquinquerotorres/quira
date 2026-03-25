<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Request;
use App\Entity\User;
use App\Entity\VisitRequest;
use App\Enum\BidStatus;
use App\Repository\VisitRequestRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class RequestAddressVoter extends Voter
{
    public const VIEW_PRECISE_ADDRESS = 'VIEW_PRECISE_ADDRESS';

    public function __construct(
        private readonly VisitRequestRepository $visitRequestRepository,
    ) {
    }

    /**
     * @param string $attribute
     * @param mixed $subject
     * @return bool
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW_PRECISE_ADDRESS && $subject instanceof Request;
    }

    /**
     * @param string $attribute
     * @param mixed $subject
     * @param TokenInterface $token
     * @param mixed $vote 
     * @return bool
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, $vote = null): bool
    {
        /** @var User|null $user */
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Request $request */
        $request = $subject;

        $clientProfile = $request->getClient();
        $userClientProfile = $user->getClientProfile();

        // El cliente siempre puede ver su propia dirección (es la suya)
        if ($clientProfile && $userClientProfile && $clientProfile->getId() === $userClientProfile->getId()) {
            return true;
        }

        // El profesional asignado puede ver preciseAddress
        $assignedPro = $request->getAssignedProfessional();
        if ($assignedPro && $assignedPro->getUser() === $user) {
            return true;
        }

        $bids = $request->getBids();
        foreach ($bids as $bid) {
            if (
                $bid->getProfessional() === $user &&
                $bid->getStatus() === BidStatus::ACCEPTED
            ) {
                return true;
            }
        }

        // Profesional con visita aceptada puede ver preciseAddress.
        $proProfile = $user->getProfessionalProfile();
        if ($proProfile !== null) {
            $visit = $this->visitRequestRepository->findOneBy([
                'request' => $request,
                'professional' => $proProfile,
                'status' => VisitRequest::STATUS_ACCEPTED,
            ]);
            if ($visit instanceof VisitRequest) {
                return true;
            }
        }

        return false;
    }
}