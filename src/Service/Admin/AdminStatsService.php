<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Entity\VisitRequest;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Agregaciones del dashboard admin (Fase 1). Sin PII — solo contadores.
 *
 * Funnel (definiciones preferibles de docs/ADMIN.md móvil):
 * - registered: users con created_at en el periodo (= newUsers).
 * - phoneVerified: de esos users, los que tienen client_profile.verified_phone
 *   O professional_profile.verified_phone = true.
 * - firstRequest: users cuya primera request (MIN created_at) cae en el periodo.
 * - firstBid: users cuya primera bid cae en el periodo.
 * - acceptedJob: requests distintas con ≥1 bid ACCEPTED cuyo updated_at cae en el periodo
 *   (proxy de “primera aceptación” sin event store; una request solo se acepta una vez).
 * - completedJob: requests con status COMPLETED y updated_at en el periodo.
 * - reviewed: reviews con created_at en el periodo.
 *
 * activePaidSubscriptions.previous: perfiles con paid_through_at estrictamente posterior
 * al fin del periodo anterior (aproximación de snapshot al cierre de previousTo).
 * cancelAtPeriodEnd: solo snapshot actual (sin previous en el contrato).
 */
final class AdminStatsService
{
    public const TIMEZONE = 'Europe/Madrid';
    public const MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(string $fromDate, string $toDate, ?\DateTimeImmutable $now = null): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $now ??= new \DateTimeImmutable('now', $tz);

        $fromDay = $this->parseDay($fromDate, $tz, 'from');
        $toDay = $this->parseDay($toDate, $tz, 'to');
        if ($toDay < $fromDay) {
            throw new BadRequestHttpException('`to` debe ser mayor o igual que `from`.');
        }

        $dayCount = ((int) $fromDay->diff($toDay)->days) + 1;
        if ($dayCount > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException(sprintf(
                'El rango no puede superar %d días.',
                self::MAX_RANGE_DAYS
            ));
        }

        $previousToDay = $fromDay->modify('-1 day');
        $previousFromDay = $fromDay->modify(sprintf('-%d days', $dayCount));

        $current = $this->boundsForInclusiveDays($fromDay, $toDay);
        $previous = $this->boundsForInclusiveDays($previousFromDay, $previousToDay);
        $previousEndExclusive = $previous['endExclusive'];

        $kpisCurrent = $this->periodKpis($current['start'], $current['endExclusive']);
        $kpisPrevious = $this->periodKpis($previous['start'], $previous['endExclusive']);

        $activeNow = $this->countActivePaid($now);
        $activePreviousEnd = $this->countActivePaid($previousEndExclusive);
        $cancelAtPeriodEnd = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM professional_profile WHERE subscription_cancel_at_period_end = 1'
        );

        return [
            'period' => [
                'from' => $fromDay->format('Y-m-d'),
                'to' => $toDay->format('Y-m-d'),
                'previousFrom' => $previousFromDay->format('Y-m-d'),
                'previousTo' => $previousToDay->format('Y-m-d'),
            ],
            'kpis' => [
                'newUsers' => ['value' => $kpisCurrent['newUsers'], 'previous' => $kpisPrevious['newUsers']],
                'newPros' => ['value' => $kpisCurrent['newPros'], 'previous' => $kpisPrevious['newPros']],
                'newRequests' => ['value' => $kpisCurrent['newRequests'], 'previous' => $kpisPrevious['newRequests']],
                'newBids' => ['value' => $kpisCurrent['newBids'], 'previous' => $kpisPrevious['newBids']],
                'acceptedBids' => ['value' => $kpisCurrent['acceptedBids'], 'previous' => $kpisPrevious['acceptedBids']],
                'completedRequests' => ['value' => $kpisCurrent['completedRequests'], 'previous' => $kpisPrevious['completedRequests']],
                'activePaidSubscriptions' => ['value' => $activeNow, 'previous' => $activePreviousEnd],
                'cancelAtPeriodEnd' => ['value' => $cancelAtPeriodEnd],
            ],
            'funnel' => $this->funnel($current['start'], $current['endExclusive']),
            'queues' => [
                'pendingApproval' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM request WHERE status = ?',
                    [RequestStatus::PENDING_APPROVAL->value]
                ),
                'pendingVisitRequests' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM visit_request WHERE status = ?',
                    [VisitRequest::STATUS_PENDING]
                ),
            ],
            'timeseries' => [
                'grain' => 'day',
                'points' => $this->timeseries($fromDay, $toDay, $current['start'], $current['endExclusive'], $tz),
            ],
        ];
    }

    private function parseDay(string $value, \DateTimeZone $tz, string $param): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($day === false || (($errors['warning_count'] ?? 0) > 0) || (($errors['error_count'] ?? 0) > 0)) {
            throw new BadRequestHttpException(sprintf('`%s` debe ser una fecha YYYY-MM-DD válida.', $param));
        }

        return $day;
    }

    /**
     * @return array{start: \DateTimeImmutable, endExclusive: \DateTimeImmutable}
     */
    private function boundsForInclusiveDays(
        \DateTimeImmutable $fromDay,
        \DateTimeImmutable $toDay,
    ): array {
        $start = $fromDay->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $endExclusive = $toDay->modify('+1 day')->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));

        return ['start' => $start, 'endExclusive' => $endExclusive];
    }

    /**
     * @return array{
     *   newUsers: int,
     *   newPros: int,
     *   newRequests: int,
     *   newBids: int,
     *   acceptedBids: int,
     *   completedRequests: int
     * }
     */
    private function periodKpis(\DateTimeImmutable $start, \DateTimeImmutable $endExclusive): array
    {
        return [
            'newUsers' => $this->countBetween('`user`', 'created_at', $start, $endExclusive),
            'newPros' => $this->countBetween('professional_profile', 'created_at', $start, $endExclusive),
            'newRequests' => $this->countBetween('request', 'created_at', $start, $endExclusive),
            'newBids' => $this->countBetween('bid', 'created_at', $start, $endExclusive),
            'acceptedBids' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM bid WHERE status = ? AND updated_at >= ? AND updated_at < ?',
                [BidStatus::ACCEPTED->value, $start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')]
            ),
            'completedRequests' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM request WHERE status = ? AND updated_at >= ? AND updated_at < ?',
                [RequestStatus::COMPLETED->value, $start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')]
            ),
        ];
    }

    private function countBetween(string $table, string $column, \DateTimeImmutable $start, \DateTimeImmutable $endExclusive): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s >= ? AND %s < ?', $table, $column, $column),
            [$start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')]
        );
    }

    private function countActivePaid(\DateTimeImmutable $asOf): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM professional_profile WHERE paid_through_at IS NOT NULL AND paid_through_at > ?',
            [$asOf->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );
    }

    /**
     * @return array{
     *   registered: int,
     *   phoneVerified: int,
     *   firstRequest: int,
     *   firstBid: int,
     *   acceptedJob: int,
     *   completedJob: int,
     *   reviewed: int
     * }
     */
    private function funnel(\DateTimeImmutable $start, \DateTimeImmutable $endExclusive): array
    {
        $startS = $start->format('Y-m-d H:i:s');
        $endS = $endExclusive->format('Y-m-d H:i:s');

        $registered = $this->countBetween('`user`', 'created_at', $start, $endExclusive);

        $phoneVerified = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(DISTINCT u.id)
            FROM `user` u
            LEFT JOIN client_profile cp ON cp.user_id = u.id
            LEFT JOIN professional_profile pp ON pp.user_id = u.id
            WHERE u.created_at >= ? AND u.created_at < ?
              AND (cp.verified_phone = 1 OR pp.verified_phone = 1)
            SQL,
            [$startS, $endS]
        );

        $firstRequest = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT r.client_id, MIN(r.created_at) AS first_at
                FROM request r
                GROUP BY r.client_id
            ) t
            WHERE t.first_at >= ? AND t.first_at < ?
            SQL,
            [$startS, $endS]
        );

        $firstBid = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT b.professional_id, MIN(b.created_at) AS first_at
                FROM bid b
                GROUP BY b.professional_id
            ) t
            WHERE t.first_at >= ? AND t.first_at < ?
            SQL,
            [$startS, $endS]
        );

        $acceptedJob = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(DISTINCT b.request_id)
            FROM bid b
            WHERE b.status = ? AND b.updated_at >= ? AND b.updated_at < ?
            SQL,
            [BidStatus::ACCEPTED->value, $startS, $endS]
        );

        $completedJob = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM request WHERE status = ? AND updated_at >= ? AND updated_at < ?',
            [RequestStatus::COMPLETED->value, $startS, $endS]
        );

        $reviewed = $this->countBetween('review', 'created_at', $start, $endExclusive);

        return [
            'registered' => $registered,
            'phoneVerified' => $phoneVerified,
            'firstRequest' => $firstRequest,
            'firstBid' => $firstBid,
            'acceptedJob' => $acceptedJob,
            'completedJob' => $completedJob,
            'reviewed' => $reviewed,
        ];
    }

    /**
     * @return list<array{date: string, newUsers: int, newRequests: int, newBids: int, acceptedBids: int}>
     */
    private function timeseries(
        \DateTimeImmutable $fromDay,
        \DateTimeImmutable $toDay,
        \DateTimeImmutable $start,
        \DateTimeImmutable $endExclusive,
        \DateTimeZone $tz,
    ): array {
        $points = [];
        for ($d = $fromDay; $d <= $toDay; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $points[$key] = [
                'date' => $key,
                'newUsers' => 0,
                'newRequests' => 0,
                'newBids' => 0,
                'acceptedBids' => 0,
            ];
        }

        $points = $this->withDailyCounts($points, '`user`', 'created_at', null, null, $start, $endExclusive, $tz, 'newUsers');
        $points = $this->withDailyCounts($points, 'request', 'created_at', null, null, $start, $endExclusive, $tz, 'newRequests');
        $points = $this->withDailyCounts($points, 'bid', 'created_at', null, null, $start, $endExclusive, $tz, 'newBids');
        $points = $this->withDailyCounts(
            $points,
            'bid',
            'updated_at',
            'status',
            BidStatus::ACCEPTED->value,
            $start,
            $endExclusive,
            $tz,
            'acceptedBids'
        );

        return array_values($points);
    }

    /**
     * @param array<string, array{date: string, newUsers: int, newRequests: int, newBids: int, acceptedBids: int}> $points
     *
     * @return array<string, array{date: string, newUsers: int, newRequests: int, newBids: int, acceptedBids: int}>
     */
    private function withDailyCounts(
        array $points,
        string $table,
        string $column,
        ?string $statusColumn,
        ?string $statusValue,
        \DateTimeImmutable $start,
        \DateTimeImmutable $endExclusive,
        \DateTimeZone $tz,
        string $field,
    ): array {
        // Agrupa en SQL por día civil Europe/Madrid (CONVERT_TZ); si el driver no tiene
        // tablas TZ, CONVERT_TZ devuelve NULL y caemos a bucket en PHP.
        $sql = sprintf(
            'SELECT DATE(CONVERT_TZ(%s, \'+00:00\', \'Europe/Madrid\')) AS day_key, COUNT(*) AS cnt
             FROM %s WHERE %s >= ? AND %s < ?',
            $column,
            $table,
            $column,
            $column
        );
        $params = [$start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')];
        if ($statusColumn !== null && $statusValue !== null) {
            $sql .= sprintf(' AND %s = ?', $statusColumn);
            $params[] = $statusValue;
        }
        $sql .= ' GROUP BY day_key';

        try {
            /** @var list<array{day_key: ?string, cnt: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, $params);
            $allNull = $rows !== [] && array_reduce(
                $rows,
                static fn (bool $carry, array $r): bool => $carry && ($r['day_key'] === null || $r['day_key'] === ''),
                true
            );
            if (!$allNull) {
                foreach ($rows as $row) {
                    $day = $row['day_key'] ?? null;
                    if (!\is_string($day) || !isset($points[$day])) {
                        continue;
                    }
                    $points[$day][$field] = (int) $row['cnt'];
                }

                return $points;
            }
        } catch (\Throwable) {
            // fallback PHP
        }

        $fallbackSql = sprintf(
            'SELECT %s AS ts FROM %s WHERE %s >= ? AND %s < ?',
            $column,
            $table,
            $column,
            $column
        );
        $fallbackParams = [$start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')];
        if ($statusColumn !== null && $statusValue !== null) {
            $fallbackSql .= sprintf(' AND %s = ?', $statusColumn);
            $fallbackParams[] = $statusValue;
        }

        /** @var list<array{ts: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($fallbackSql, $fallbackParams);
        foreach ($rows as $row) {
            $day = (new \DateTimeImmutable((string) $row['ts'], new \DateTimeZone('UTC')))
                ->setTimezone($tz)
                ->format('Y-m-d');
            if (!isset($points[$day])) {
                continue;
            }
            ++$points[$day][$field];
        }

        return $points;
    }
}
