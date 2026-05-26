<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$config = require __DIR__ . '/../config/config.php';
$googleKey = trim((string) ($config['apis']['google_geocoding_key'] ?? ''));
$city = normalizeLeadText((string) ($_GET['city'] ?? 'Delhi'), 100);
$state = normalizeLeadText((string) ($_GET['state'] ?? 'Delhi'), 100);
$location = ['lat' => null, 'lng' => null, 'source' => ''];
$googleStatus = 'Not configured';

if ($googleKey !== '') {
    $query = trim(implode(', ', array_filter([$city, $state, 'India'])));
    $googleData = googleGeocode($query, $googleKey);
    $googleStatus = is_array($googleData) ? (string) ($googleData['status'] ?? 'UNKNOWN') : 'REQUEST_FAILED';

    if ($googleStatus === 'OK' && !empty($googleData['results'][0]['geometry']['location'])) {
        $loc = $googleData['results'][0]['geometry']['location'];
        $location = [
            'lat' => (float) $loc['lat'],
            'lng' => (float) $loc['lng'],
            'source' => 'google',
        ];
    }
} else {
    $location = geocodeStudentLocation($city, $state);
}

$hasLocation = $location['lat'] !== null && $location['lng'] !== null;
$lat = $hasLocation ? (float) $location['lat'] : 0.0;
$lng = $hasLocation ? (float) $location['lng'] : 0.0;
$googleMapsUrl = $hasLocation
    ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($lat . ',' . $lng)
    : '';
$radiusKm = 10;
$nearbyAreas = $hasLocation ? allAreasWithinRadius($lat, $lng, $radiusKm) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Status</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --ink: #172033;
            --muted: #667085;
            --paper: #ffffff;
            --line: #dbe4ef;
            --green: #08745f;
            --blue: #215ea8;
            --red: #b42318;
            --amber: #9a5b00;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background: #eef3f8;
        }
        .topbar {
            padding: 16px 28px;
            background: #101828;
            color: #fff;
            display: flex;
            justify-content: space-between;
            gap: 16px;
        }
        .topbar a { color: #fff; font-weight: 700; text-decoration: none; }
        .shell { width: min(900px, calc(100% - 32px)); margin: 28px auto; }
        .panel {
            padding: 22px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--paper);
            box-shadow: 0 16px 38px rgba(16,24,40,.08);
        }
        h1 { margin: 0 0 8px; font-size: 30px; }
        p { color: var(--muted); line-height: 1.55; }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .box {
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
        }
        .box strong { display: block; margin-bottom: 6px; font-size: 22px; }
        .label { color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
        form { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; margin-top: 18px; }
        input {
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #b9c3d3;
            border-radius: 6px;
            font-size: 15px;
        }
        button, .button {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: 0;
            border-radius: 6px;
            background: var(--blue);
            color: #fff;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }
        .ok { color: var(--green); }
        .bad { color: var(--red); }
        .warn { color: var(--amber); }
        code { background: #f1f5f9; padding: 2px 5px; border-radius: 4px; }
        .map-panel {
            margin-top: 18px;
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
            background: #f8fafc;
        }
        #map {
            height: 420px;
            width: 100%;
        }
        .map-foot {
            padding: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .map-foot p { margin: 0; }
        .nearby-list {
            margin-top: 18px;
            display: grid;
            gap: 10px;
        }
        .nearby-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
        }
        .nearby-item strong { display: block; margin-bottom: 4px; }
        .distance-pill {
            min-width: 76px;
            padding: 6px 9px;
            border-radius: 999px;
            background: #dcfce7;
            color: var(--green);
            font-size: 13px;
            font-weight: 800;
            text-align: center;
        }
        .legend {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 13px;
        }
        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 999px;
            background: rgba(33,94,168,.28);
            border: 2px solid #215ea8;
        }
        @media (max-width: 720px) {
            .grid, form { grid-template-columns: 1fr; }
            #map { height: 320px; }
            .nearby-item { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <strong>API Status</strong>
        <a href="index.php">Back to Dashboard</a>
    </header>

    <main class="shell">
        <section class="panel">
            <h1>Location API Test</h1>
            <p>Google Geocoding is optional. If <code>apis.google_geocoding_key</code> is blank, student uploads use free local Indian city coordinates first, then OpenStreetMap fallback to find latitude and longitude from city/state before lead matching.</p>

            <form action="api_status.php" method="get">
                <input name="city" value="<?= e($city) ?>" placeholder="City">
                <input name="state" value="<?= e($state) ?>" placeholder="State">
                <button type="submit">Test Location</button>
            </form>

            <div class="grid">
                <div class="box">
                    <span class="label">Google API Key</span>
                    <?php if ($googleKey !== ''): ?>
                        <strong class="ok">Configured</strong>
                    <?php else: ?>
                        <strong class="warn">Missing</strong>
                    <?php endif; ?>
                </div>
                <div class="box">
                    <span class="label">Google Status</span>
                    <strong class="<?= $googleStatus === 'OK' ? 'ok' : ($googleKey === '' ? 'warn' : 'bad') ?>"><?= e($googleStatus) ?></strong>
                </div>
                <div class="box">
                    <span class="label">Latitude</span>
                    <strong><?= $location['lat'] !== null ? e((string) $location['lat']) : 'Not found' ?></strong>
                </div>
                <div class="box">
                    <span class="label">Longitude</span>
                    <strong><?= $location['lng'] !== null ? e((string) $location['lng']) : 'Not found' ?></strong>
                </div>
            </div>

            <p>Current source: <strong><?= e($location['source'] !== '' ? $location['source'] : 'none') ?></strong></p>

            <?php if ($hasLocation): ?>
                <div class="map-panel">
                    <div id="map" aria-label="Map for <?= e(trim($city . ', ' . $state, ' ,')) ?>"></div>
                    <div class="map-foot">
                        <p><strong><?= e(trim($city . ', ' . $state, ' ,')) ?></strong> is shown at <?= e((string) $lat) ?>, <?= e((string) $lng) ?>.</p>
                        <span class="legend"><span class="legend-dot"></span><?= $radiusKm ?> KM area circle</span>
                        <a class="button" href="<?= e($googleMapsUrl) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
                    </div>
                </div>

                <div class="panel" style="margin-top:18px; box-shadow:none;">
                    <h2>All Areas Within <?= $radiusKm ?> KM</h2>
                    <?php if ($nearbyAreas === []): ?>
                        <p>No areas were found from OpenStreetMap or the built-in free list inside this radius. The map circle still shows the full <?= $radiusKm ?> KM coverage area.</p>
                    <?php else: ?>
                        <div class="nearby-list">
                            <?php foreach ($nearbyAreas as $area): ?>
                                <div class="nearby-item">
                                    <div>
                                        <strong><?= e($area['name']) ?></strong>
                                        <span class="label">
                                            <?= e(trim((string) ($area['city'] ?? '') . ', ' . (string) ($area['state'] ?? ''), ' ,') ?: ((string) ($area['source'] ?? 'local_free'))) ?>
                                        </span>
                                    </div>
                                    <span class="distance-pill"><?= e((string) $area['distance_km']) ?> KM</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="map-panel">
                    <div class="map-foot">
                        <p>No map available because latitude and longitude were not found for this city/state.</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php if ($hasLocation): ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            const map = L.map('map', { scrollWheelZoom: false }).setView([<?= json_encode($lat) ?>, <?= json_encode($lng) ?>], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            L.marker([<?= json_encode($lat) ?>, <?= json_encode($lng) ?>])
                .addTo(map)
                .bindPopup(<?= json_encode(trim($city . ', ' . $state, ' ,')) ?>);

            L.circle([<?= json_encode($lat) ?>, <?= json_encode($lng) ?>], {
                radius: <?= $radiusKm * 1000 ?>,
                color: '#215ea8',
                weight: 3,
                fillColor: '#2f80ed',
                fillOpacity: 0.18
            }).addTo(map);

            const nearbyAreas = <?= json_encode($nearbyAreas, JSON_UNESCAPED_SLASHES) ?>;
            nearbyAreas.forEach((area) => {
                L.circleMarker([area.lat, area.lng], {
                    radius: 7,
                    color: '#08745f',
                    fillColor: '#12b76a',
                    fillOpacity: 0.9,
                    weight: 2
                }).addTo(map).bindPopup(`${area.name}<br>${area.distance_km} KM`);
            });
        </script>
    <?php endif; ?>
</body>
</html>
