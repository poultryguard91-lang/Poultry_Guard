<?php
/**
 * GET /api/reports/productivity.php[?flock_id=&device_id=&house=&from=&to=]
 *
 * Productivity reporting built around the Feed Conversion Ratio:
 *
 *     FCR = feed consumed (kg) / live weight gain (kg)
 *
 * A lower FCR means the flock converts feed into body weight more efficiently.
 * The feed figure comes from the same mass-balance calculation the feed report
 * uses, so the FCR is derived from measured load-cell data rather than from
 * anything typed in by hand; only the bird weights and counts are manual.
 *
 * POST is used to record a weigh-in (production.manage permission).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET', 'POST');

api_run(function (array $in): ApiResult {
    if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
        return record_weigh_in($in);
    }

    require_permission('reports.view');

    // ------------------------------------------------------------------
    // Filters: flock, device, poultry house, date range
    // ------------------------------------------------------------------
    $where  = [];
    $params = [];

    if (!empty($in['flock_id']) && $in['flock_id'] !== 'all') {
        $where[]  = 'f.flock_id = ?';
        $params[] = v_int($in['flock_id'], 'Flock', 1, PHP_INT_MAX);
    }
    if (!empty($in['device_id']) && $in['device_id'] !== 'all') {
        $where[]  = 'f.device_id = ?';
        $params[] = v_int($in['device_id'], 'Device', 1, PHP_INT_MAX);
    }
    if (!empty($in['house']) && $in['house'] !== 'all') {
        $where[]  = 'f.house_name = ?';
        $params[] = v_string($in['house'], 'Poultry house', 1, 100);
    }
    if (!empty($in['status']) && $in['status'] !== 'all') {
        $where[]  = 'f.status = ?';
        $params[] = v_enum($in['status'], 'Status', ['active', 'closed']);
    }
    if (!empty($in['from'])) {
        $where[]  = 'f.start_date >= ?';
        $params[] = v_date($in['from'], 'From date');
    }
    if (!empty($in['to'])) {
        $where[]  = 'f.start_date <= ?';
        $params[] = v_date($in['to'], 'To date');
    }

    $sql = 'SELECT f.*, d.device_name FROM flocks f JOIN devices d ON d.device_id = f.device_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY f.start_date DESC';

    $st = db()->prepare($sql);
    $st->execute($params);
    $flocks = $st->fetchAll();

    $rows = [];
    foreach ($flocks as $f) {
        $fcr = flock_fcr((int)$f['flock_id']);
        if ($fcr === null) {
            // Fewer than two weigh-ins: no growth interval yet, so no FCR.
            $rows[] = [
                'flock_id'    => (int)$f['flock_id'],
                'flock_name'  => $f['flock_name'],
                'house_name'  => $f['house_name'],
                'breed'       => $f['breed'],
                'device_id'   => (int)$f['device_id'],
                'device_name' => $f['device_name'],
                'status'      => $f['status'],
                'start_date'  => $f['start_date'],
                'fcr'         => null,
                'note'        => 'At least two weigh-ins are needed before an FCR can be calculated.',
            ];
            continue;
        }
        $fcr['device_name'] = $f['device_name'];
        $fcr['status']      = $f['status'];
        // The per-flock weigh-in list is only needed for the detail view.
        $fcr['records']     = array_map(static fn(array $r): array => [
            'record_date' => $r['record_date'],
            'bird_count'  => (int)$r['bird_count'],
            'avg_weight'  => (float)$r['avg_weight'],
            'mortality'   => (int)$r['mortality'],
        ], $fcr['records']);
        $rows[] = $fcr;
    }

    // Farm-wide roll-up across everything that could be calculated.
    $withFcr   = array_filter($rows, static fn(array $r): bool => ($r['fcr'] ?? null) !== null);
    $totalFeed = array_sum(array_map(static fn(array $r): float => (float)($r['feed_consumed_kg'] ?? 0), $withFcr));
    $totalGain = array_sum(array_map(static fn(array $r): float => (float)($r['weight_gain_kg'] ?? 0), $withFcr));
    $birds     = array_sum(array_map(static fn(array $r): int => (int)($r['current_count'] ?? 0), $withFcr));

    $houses = db()->query('SELECT DISTINCT house_name FROM flocks ORDER BY house_name')
        ->fetchAll(PDO::FETCH_COLUMN);

    return api_ok([
        'flocks'  => $rows,
        'houses'  => $houses,
        'summary' => [
            'flocks_reported'  => count($rows),
            'flocks_with_fcr'  => count($withFcr),
            'total_birds'      => $birds,
            'total_feed_kg'    => round($totalFeed, 3),
            'total_gain_kg'    => round($totalGain, 3),
            // The farm-level FCR is the ratio of the totals, not the mean of
            // the per-flock ratios, which would over-weight small flocks.
            'overall_fcr'      => $totalGain > 0 ? round($totalFeed / $totalGain, 3) : null,
            'average_mortality'=> $withFcr === [] ? 0.0 : round(
                array_sum(array_map(static fn(array $r): float => (float)($r['mortality_rate'] ?? 0), $withFcr)) / count($withFcr), 2
            ),
        ],
    ], 'Productivity report generated successfully');
});

/**
 * POST - record a weigh-in for a flock.
 * Body: { flock_id, record_date, bird_count, avg_weight, mortality, notes }
 */
function record_weigh_in(array $in): ApiResult
{
    $user = require_permission('production.manage');
    require_csrf($in);

    $flockId = v_int($in['flock_id'] ?? null, 'Flock', 1, PHP_INT_MAX);

    $st = db()->prepare('SELECT * FROM flocks WHERE flock_id = ?');
    $st->execute([$flockId]);
    $flock = $st->fetch();
    if (!$flock) {
        throw new NotFoundException('That flock could not be found.');
    }

    $date      = v_date($in['record_date'] ?? null, 'Record date', date('Y-m-d'));
    $count     = v_int($in['bird_count'] ?? null, 'Bird count', 0, 1000000);
    $avgWeight = v_float($in['avg_weight'] ?? null, 'Average weight', 1, 20000);
    $mortality = v_int($in['mortality'] ?? null, 'Mortality', 0, 1000000, 0);
    $notes     = v_string($in['notes'] ?? null, 'Notes', 0, 255, '');

    if (strtotime($date) < strtotime((string)$flock['start_date'])) {
        throw new ValidationException('The weigh-in date cannot be before the flock start date.');
    }
    if (strtotime($date) > time()) {
        throw new ValidationException('The weigh-in date cannot be in the future.');
    }
    if ($count > (int)$flock['initial_count']) {
        throw new ValidationException('The bird count cannot exceed the number placed at the start of the flock.');
    }

    // One weigh-in per flock per day; re-submitting corrects that day's entry.
    db()->prepare(
        'INSERT INTO production_records (flock_id, record_date, bird_count, avg_weight, mortality, notes, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE bird_count = VALUES(bird_count), avg_weight = VALUES(avg_weight),
                                 mortality = VALUES(mortality), notes = VALUES(notes),
                                 recorded_by = VALUES(recorded_by)'
    )->execute([
        $flockId, $date, $count, $avgWeight, $mortality,
        $notes !== '' ? $notes : null, (int)$user['user_id'],
    ]);

    log_activity('PRODUCTION_RECORD', sprintf(
        'Weigh-in recorded for %s on %s: %d birds at %.0f g.',
        $flock['flock_name'], $date, $count, $avgWeight
    ), (int)$user['user_id']);

    return api_ok([
        'flock_id' => $flockId,
        'fcr'      => flock_fcr($flockId),
    ], 'Weigh-in recorded successfully');
}
