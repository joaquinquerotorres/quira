<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Bid;
use App\Entity\Request;
use App\Entity\User;
use App\Entity\VisitRequest;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Service\ProfessionalSubscriptionService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly ProfessionalSubscriptionService $subscriptionService,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $queryNameGenerator, $resourceClass, true);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $queryNameGenerator, $resourceClass, false);
    }

    private function addWhere(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, bool $isCollection): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $isPro = $this->isProfessional($user);
        $httpRequest = $this->requestStack->getCurrentRequest();

        if ($resourceClass === Bid::class) {
            if ($isCollection) {
                if ($httpRequest && $httpRequest->query->get('my_bids') === 'true') {
                    $queryBuilder
                        ->andWhere(sprintf('%s.professional = :current_user', $rootAlias))
                        ->andWhere(sprintf('%s.status != :my_bids_excluded_status', $rootAlias))
                        ->setParameter('my_bids_excluded_status', BidStatus::REJECTED)
                        ->setParameter('current_user', $user);

                    $searchReqTitle = $httpRequest->query->get('request.title') ?? $httpRequest->query->get('request_title');
                    if ($searchReqTitle) {
                        $queryBuilder
                            ->join(sprintf('%s.request', $rootAlias), 'search_bid_req')
                            ->andWhere('search_bid_req.title LIKE :search_title')
                            ->setParameter('search_title', '%' . $searchReqTitle . '%');
                    }

                    $searchReqCategory = $httpRequest->query->get('request.category') ?? $httpRequest->query->get('request_category');
                    if ($searchReqCategory) {
                        if (!str_contains($queryBuilder->getDQL(), 'search_bid_req')) {
                            $queryBuilder->join(sprintf('%s.request', $rootAlias), 'search_bid_req');
                        }
                        $queryBuilder
                            ->andWhere('search_bid_req.category = :search_category')
                            ->setParameter('search_category', $searchReqCategory);
                    }
                } else {
                    $queryBuilder
                        ->leftJoin(sprintf('%s.request', $rootAlias), 'bid_r')
                        ->leftJoin('bid_r.client', 'bid_c')
                        ->andWhere('bid_c.user = :current_user')
                        ->setParameter('current_user', $user);
                }
            } else {
                // Single bid: client can see bids on their requests, professional can see their own bids (e.g. to withdraw)
                $queryBuilder
                    ->leftJoin(sprintf('%s.request', $rootAlias), 'bid_r')
                    ->leftJoin('bid_r.client', 'bid_c')
                    ->andWhere(
                        $queryBuilder->expr()->orX(
                            'bid_c.user = :current_user',
                            sprintf('%s.professional = :current_user', $rootAlias)
                        )
                    )
                    ->setParameter('current_user', $user);
            }
        }

        if ($resourceClass === Request::class) {
            if (!$isCollection) {
                $bidAlias = $queryNameGenerator->generateJoinAlias('bid');
                $visitAlias = $queryNameGenerator->generateJoinAlias('visit');
                $proProfile = $user->getProfessionalProfile();
                $hasActivePaidSubscription = $proProfile !== null
                    && $this->subscriptionService->hasActivePaidSubscription($proProfile);

                $queryBuilder
                    ->leftJoin(sprintf('%s.client', $rootAlias), 'req_client')
                    ->leftJoin(sprintf('%s.assignedProfessional', $rootAlias), 'req_pro')
                    ->leftJoin(
                        sprintf('%s.bids', $rootAlias),
                        $bidAlias,
                        'WITH',
                        sprintf(
                            '%s.professional = :current_user AND %s.status IN (:bid_item_active_statuses)',
                            $bidAlias,
                            $bidAlias
                        )
                    )
                    ->leftJoin(
                        sprintf('%s.visitRequests', $rootAlias),
                        $visitAlias,
                        'WITH',
                        sprintf('%s.professional = :current_pro_profile AND %s.status = :visit_accepted', $visitAlias, $visitAlias)
                    )
                    ->andWhere(
                        $queryBuilder->expr()->orX(
                            'req_client.user = :current_user',
                            'req_pro.user = :current_user',
                            sprintf(
                                '(%s.status = :status_pending AND (%s.riskLevel != :risk_high_item OR :has_paid_subscription_item = true))',
                                $rootAlias,
                                $rootAlias
                            ),
                            sprintf('%s.id IS NOT NULL', $bidAlias),
                            sprintf('%s.id IS NOT NULL', $visitAlias)
                        )
                    )
                    ->setParameter('current_user', $user)
                    ->setParameter('current_pro_profile', $proProfile)
                    ->setParameter('visit_accepted', VisitRequest::STATUS_ACCEPTED)
                    ->setParameter('status_pending', RequestStatus::PENDING->value)
                    ->setParameter('risk_high_item', RiskLevel::HIGH)
                    ->setParameter('has_paid_subscription_item', $hasActivePaidSubscription)
                    ->setParameter('bid_item_active_statuses', [BidStatus::PENDING, BidStatus::ACCEPTED]);
                return;
            }

           
            if (!$isPro) {
                $queryBuilder
                    ->join(sprintf('%s.client', $rootAlias), 'req_client_list')
                    ->andWhere('req_client_list.user = :current_user')
                    ->setParameter('current_user', $user);
                
                if ($httpRequest && $searchTitle = $httpRequest->query->get('title')) {
                    $queryBuilder
                        ->andWhere(sprintf('%s.title LIKE :search_title', $rootAlias))
                        ->setParameter('search_title', '%' . $searchTitle . '%');
                }
                return;
            }

            if ($httpRequest && $httpRequest->query->get('is_market') === 'true') {
                $proProfile = $user->getProfessionalProfile();
                $skills = $proProfile->getSkills();
                
                if (!empty($skills)) {
                    $queryBuilder
                        ->andWhere(sprintf('%s.category IN (:skills)', $rootAlias))
                        ->setParameter('skills', $skills);
                } else {
                    $queryBuilder->andWhere('1 = 0'); 
                }

                $proLocation = $proProfile->getLocationPoint();
                $radius = $proProfile->getServiceRadiusKm();

                if ($proLocation && $radius) {
                    $centerParam = $queryNameGenerator->generateParameterName('center');
                    $radiusParam = $queryNameGenerator->generateParameterName('radius');
                    $longitude = $proLocation->getLongitude();
                    $latitude = $proLocation->getLatitude();

                    $queryBuilder
                        ->andWhere(sprintf(
                            'ST_Distance_Sphere(%s.locationPoint, ST_GeomFromText(:%s)) <= :%s',
                            $rootAlias,
                            $centerParam,
                            $radiusParam
                        ))
                        ->setParameter($centerParam, sprintf('POINT(%f %f)', $longitude, $latitude))
                        ->setParameter($radiusParam, $radius * 1000); 
                }
                
                $queryBuilder
                    ->andWhere(sprintf('%s.status = :market_status', $rootAlias))
                    ->setParameter('market_status', RequestStatus::PENDING->value);
                    
                $queryBuilder
                    ->join(sprintf('%s.client', $rootAlias), 'mkt_client')
                    ->andWhere('mkt_client.user != :current_user')
                    ->setParameter('current_user', $user);

                if (!$this->subscriptionService->hasActivePaidSubscription($proProfile)) {
                    $highExceptionDql = sprintf(
                        'EXISTS (SELECT 1 FROM %s bid_high WHERE bid_high.request = %s AND bid_high.professional = :current_user AND bid_high.status IN (:bid_statuses_high_exception))',
                        Bid::class,
                        $rootAlias
                    );
                    $queryBuilder
                        ->andWhere(
                            $queryBuilder->expr()->orX(
                                sprintf('%s.riskLevel != :risk_level_high', $rootAlias),
                                sprintf('%s.assignedProfessional = :current_pro_profile', $rootAlias),
                                $highExceptionDql
                            )
                        )
                        ->setParameter('risk_level_high', RiskLevel::HIGH)
                        ->setParameter('current_pro_profile', $proProfile)
                        ->setParameter('bid_statuses_high_exception', [BidStatus::PENDING, BidStatus::ACCEPTED]);
                }

            } elseif ($httpRequest && $httpRequest->query->get('my_jobs') === 'true') {
                 $queryBuilder
                    ->join(sprintf('%s.assignedProfessional', $rootAlias), 'my_job_pro')
                    ->andWhere('my_job_pro.user = :current_user')
                    ->andWhere(sprintf('%s.status IN (:status_accepted)', $rootAlias))
                    ->setParameter('current_user', $user)
                    ->setParameter('status_accepted', [RequestStatus::ACCEPTED->value, RequestStatus::COMPLETED->value]);

            } elseif ($httpRequest && $httpRequest->query->get('history') === 'true') {
                $queryBuilder
                    ->join(sprintf('%s.assignedProfessional', $rootAlias), 'my_job_pro')
                    ->andWhere('my_job_pro.user = :current_user')
                    ->andWhere(sprintf('%s.status = :status_completed', $rootAlias)) 
                    ->setParameter('current_user', $user)
                    ->setParameter('status_completed', RequestStatus::COMPLETED->value);

            } elseif ($httpRequest && $httpRequest->query->get('my_requests') === 'true') {
                $queryBuilder
                    ->join(sprintf('%s.client', $rootAlias), 'my_req_client')
                    ->andWhere('my_req_client.user = :current_user')
                    ->andWhere(sprintf('%s.status = :status_completed', $rootAlias)) 
                    ->setParameter('current_user', $user)
                    ->setParameter('status_completed', RequestStatus::COMPLETED->value);
            } else {
                $queryBuilder
                    ->join(sprintf('%s.client', $rootAlias), 'my_req_client')
                    ->andWhere('my_req_client.user = :current_user')
                    ->setParameter('current_user', $user);
            }

            if ($httpRequest && $searchTitle = $httpRequest->query->get('title')) {
                $queryBuilder
                    ->andWhere(sprintf('%s.title LIKE :search_title', $rootAlias))
                    ->setParameter('search_title', '%' . $searchTitle . '%');
            }
        }
    }

    private function isProfessional(User $user): bool
    {
        $roles = $user->getRoles();
        
        $hasProRole = in_array('ROLE_PRO', $roles, true) 
                   || in_array('ROLE_SOLVER', $roles, true) 
                   || in_array('ROLE_FREE', $roles, true)
                   || in_array('ROLE_PROFESSIONAL', $roles, true);
        return $hasProRole && $user->getProfessionalProfile() !== null;
    }
}