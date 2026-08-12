<?php

declare(strict_types=1);

use PauBerlioz\FfeSync\Database;

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=900');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$history = ($_GET['history'] ?? '0') === '1';
$operator = $history ? '>=' : '>=';
$threshold = $history ? 'DATE_SUB(CURDATE(), INTERVAL 18 MONTH)' : 'CURDATE()';

$statement = Database::connection()->query(
    'SELECT
        g.id,
        g.title,
        g.start_date AS date,
        g.end_date AS endDate,
        CONCAT_WS(" — ", NULLIF(g.city, ""), NULLIF(g.department, "")) AS location,
        "Tournoi homologué FFE à proximité de Pau." AS description,
        COALESCE(registration.registration_url, source.ffe_url) AS registrationUrl,
        g.cadence_kind AS cadenceKind,
        source.cadence,
        source.ffe_url AS ffeUrl,
        source.results_url AS resultsUrl,
        g.distance_km AS distanceKm
     FROM pbe_event_groups g
     INNER JOIN pbe_event_group_sources link ON link.group_id = g.id AND link.source_order = 1
     INNER JOIN pbe_tournament_sources source ON source.ffe_ref = link.ffe_ref
     LEFT JOIN (
        SELECT link2.group_id, MAX(NULLIF(source2.registration_url, "")) AS registration_url
        FROM pbe_event_group_sources link2
        INNER JOIN pbe_tournament_sources source2 ON source2.ffe_ref = link2.ffe_ref
        GROUP BY link2.group_id
     ) registration ON registration.group_id = g.id
     WHERE source.is_excluded = 0
       AND COALESCE(g.end_date, g.start_date) ' . $operator . ' ' . $threshold . '
     ORDER BY g.start_date DESC'
);

echo json_encode(
    ['items' => $statement->fetchAll()],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
