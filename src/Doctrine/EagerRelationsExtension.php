<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Bid;
use App\Entity\Request;
use Doctrine\ORM\QueryBuilder;

/**
 * Eager-load de relaciones que ya se serializan en request:read / bid:read,
 * para evitar N+1 con force_eager: false.
 *
 * Debe ejecutarse DESPUÉS de CurrentUserExtension (priority más baja).
 * Reutiliza aliases de client/assigned ya unidos por filtros de acceso;
 * OneToMany (bids/visits/questions) usa aliases propios (nunca joins WITH filtrados).
 *
 * Usa DISTINCT cuando hay OneToMany para no romper la paginación.
 */
final class EagerRelationsExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->apply($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->apply($queryBuilder, $resourceClass);
    }

    private function apply(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass === Request::class) {
            $this->eagerLoadRequest($queryBuilder);

            return;
        }

        if ($resourceClass === Bid::class) {
            $this->eagerLoadBid($queryBuilder);
        }
    }

    private function eagerLoadRequest(QueryBuilder $queryBuilder): void
    {
        $root = $queryBuilder->getRootAliases()[0];

        $this->ensureAssociationSelect(
            $queryBuilder,
            $root,
            'client',
            ['req_client', 'req_client_list', 'mkt_client', 'my_req_client', 'eager_req_client'],
            'eager_req_client'
        );
        $this->ensureAssociationSelect(
            $queryBuilder,
            $root,
            'assignedProfessional',
            ['req_pro', 'my_job_pro', 'eager_req_assigned'],
            'eager_req_assigned'
        );

        $dql = $queryBuilder->getDQL();
        if (!$this->dqlHasAlias($dql, 'eager_req_bids')) {
            $queryBuilder
                ->leftJoin(sprintf('%s.bids', $root), 'eager_req_bids')
                ->addSelect('eager_req_bids')
                ->leftJoin('eager_req_bids.professional', 'eager_bid_pro_user')
                ->addSelect('eager_bid_pro_user')
                ->leftJoin('eager_bid_pro_user.professionalProfile', 'eager_bid_pro_profile')
                ->addSelect('eager_bid_pro_profile');
        }

        $dql = $queryBuilder->getDQL();
        if (!$this->dqlHasAlias($dql, 'eager_req_visits')) {
            $queryBuilder
                ->leftJoin(sprintf('%s.visitRequests', $root), 'eager_req_visits')
                ->addSelect('eager_req_visits')
                ->leftJoin('eager_req_visits.professional', 'eager_visit_pro')
                ->addSelect('eager_visit_pro');
        }

        $dql = $queryBuilder->getDQL();
        if (!$this->dqlHasAlias($dql, 'eager_req_questions')) {
            $queryBuilder
                ->leftJoin(sprintf('%s.questions', $root), 'eager_req_questions')
                ->addSelect('eager_req_questions')
                ->leftJoin('eager_req_questions.author', 'eager_question_author')
                ->addSelect('eager_question_author');
        }

        $queryBuilder->distinct();
    }

    private function eagerLoadBid(QueryBuilder $queryBuilder): void
    {
        $root = $queryBuilder->getRootAliases()[0];

        $this->ensureAssociationSelect(
            $queryBuilder,
            $root,
            'request',
            ['bid_r', 'search_bid_req', 'eager_bid_request'],
            'eager_bid_request'
        );

        $dql = $queryBuilder->getDQL();
        // Sub-relaciones de request embebido en bid:read
        if ($this->dqlHasAlias($dql, 'eager_bid_request') || $this->dqlHasAlias($dql, 'bid_r') || $this->dqlHasAlias($dql, 'search_bid_req')) {
            $reqAlias = $this->firstExistingAlias($dql, ['eager_bid_request', 'bid_r', 'search_bid_req']) ?? 'eager_bid_request';
            if (!$this->dqlHasAlias($dql, 'eager_bid_req_client')) {
                // bid_c puede existir en CurrentUserExtension
                if ($this->dqlHasAlias($dql, 'bid_c')) {
                    $queryBuilder->addSelect('bid_c');
                } else {
                    $queryBuilder
                        ->leftJoin(sprintf('%s.client', $reqAlias), 'eager_bid_req_client')
                        ->addSelect('eager_bid_req_client');
                }
            }
            $dql = $queryBuilder->getDQL();
            if (!$this->dqlHasAlias($dql, 'eager_bid_req_assigned')) {
                $queryBuilder
                    ->leftJoin(sprintf('%s.assignedProfessional', $reqAlias), 'eager_bid_req_assigned')
                    ->addSelect('eager_bid_req_assigned');
            }
        }

        $dql = $queryBuilder->getDQL();
        if (!$this->dqlHasAlias($dql, 'eager_bid_professional')) {
            $queryBuilder
                ->leftJoin(sprintf('%s.professional', $root), 'eager_bid_professional')
                ->addSelect('eager_bid_professional')
                ->leftJoin('eager_bid_professional.professionalProfile', 'eager_bid_professional_profile')
                ->addSelect('eager_bid_professional_profile');
        }

        $queryBuilder->distinct();
    }

    /**
     * @param list<string> $candidateAliases
     */
    private function ensureAssociationSelect(
        QueryBuilder $queryBuilder,
        string $root,
        string $association,
        array $candidateAliases,
        string $newAlias
    ): void {
        $dql = $queryBuilder->getDQL();
        $existing = $this->firstExistingAlias($dql, $candidateAliases);
        if ($existing !== null) {
            $queryBuilder->addSelect($existing);

            return;
        }

        $queryBuilder
            ->leftJoin(sprintf('%s.%s', $root, $association), $newAlias)
            ->addSelect($newAlias);
    }

    /**
     * @param list<string> $aliases
     */
    private function firstExistingAlias(string $dql, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if ($this->dqlHasAlias($dql, $alias)) {
                return $alias;
            }
        }

        return null;
    }

    private function dqlHasAlias(string $dql, string $alias): bool
    {
        return (bool) preg_match('/\b'.preg_quote($alias, '/').'\b/', $dql);
    }
}
