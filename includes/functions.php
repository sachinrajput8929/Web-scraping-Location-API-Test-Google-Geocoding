<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text !== '' ? $text : 'college-' . time();
}

function uniqueCollegeSlug(mysqli $conn, string $collegeName): string
{
    $baseSlug = slugify($collegeName);
    $slug = $baseSlug;
    $counter = 2;

    ensureScraperSchema($conn);

    $stmt = $conn->prepare('SELECT id FROM colleges WHERE slug = ? LIMIT 1');

    while (true) {
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function ensureScraperSchema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM colleges');
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }

    $required = [
        'college_name' => 'ADD COLUMN college_name VARCHAR(255) DEFAULT NULL',
        'clg_name' => 'ADD COLUMN clg_name VARCHAR(200) DEFAULT NULL',
        'title' => 'ADD COLUMN title VARCHAR(255) DEFAULT NULL',
        'heading' => 'ADD COLUMN heading VARCHAR(255) DEFAULT NULL',
        'short_description' => 'ADD COLUMN short_description TEXT DEFAULT NULL',
        'long_description' => 'ADD COLUMN long_description TEXT DEFAULT NULL',
        'course_name' => 'ADD COLUMN course_name VARCHAR(200) DEFAULT NULL',
        'fees' => 'ADD COLUMN fees VARCHAR(255) DEFAULT NULL',
        'facilities' => 'ADD COLUMN facilities TEXT DEFAULT NULL',
        'address' => 'ADD COLUMN address TEXT DEFAULT NULL',
        'state' => 'ADD COLUMN state VARCHAR(100) DEFAULT NULL',
        'city' => 'ADD COLUMN city VARCHAR(100) DEFAULT NULL',
        'pincode' => 'ADD COLUMN pincode VARCHAR(20) DEFAULT NULL',
        'logo' => 'ADD COLUMN logo VARCHAR(255) DEFAULT NULL',
        'photos' => 'ADD COLUMN photos TEXT DEFAULT NULL',
        'facebook' => 'ADD COLUMN facebook VARCHAR(255) NOT NULL DEFAULT ""',
        'instagram' => 'ADD COLUMN instagram VARCHAR(255) NOT NULL DEFAULT ""',
        'twitter' => 'ADD COLUMN twitter VARCHAR(255) NOT NULL DEFAULT ""',
        'linkedin' => 'ADD COLUMN linkedin VARCHAR(255) NOT NULL DEFAULT ""',
        'slug' => 'ADD COLUMN slug VARCHAR(255) DEFAULT NULL',
        'website' => 'ADD COLUMN website VARCHAR(255) DEFAULT NULL',
        'email' => 'ADD COLUMN email VARCHAR(255) DEFAULT NULL',
        'phone' => 'ADD COLUMN phone VARCHAR(100) DEFAULT NULL',
        'latitude' => 'ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL',
        'longitude' => 'ADD COLUMN longitude DECIMAL(11,8) DEFAULT NULL',
        'courses' => 'ADD COLUMN courses TEXT DEFAULT NULL',
        'description' => 'ADD COLUMN description LONGTEXT DEFAULT NULL',
        'images' => 'ADD COLUMN images LONGTEXT DEFAULT NULL',
        'important_links' => 'ADD COLUMN important_links LONGTEXT DEFAULT NULL',
        'source_pages' => 'ADD COLUMN source_pages LONGTEXT DEFAULT NULL',
        'created_at' => 'ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        'seo_title' => 'ADD COLUMN seo_title VARCHAR(255) DEFAULT NULL',
        'seo_description' => 'ADD COLUMN seo_description TEXT DEFAULT NULL',
        'seo_keywords' => 'ADD COLUMN seo_keywords TEXT DEFAULT NULL',
        'seo_content' => 'ADD COLUMN seo_content LONGTEXT DEFAULT NULL',
        'scrape_status' => "ADD COLUMN scrape_status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending'",
        'scrape_error' => 'ADD COLUMN scrape_error TEXT DEFAULT NULL',
        'scrape_started_at' => 'ADD COLUMN scrape_started_at DATETIME DEFAULT NULL',
        'scraped_at' => 'ADD COLUMN scraped_at DATETIME DEFAULT NULL',
        'admission_process' => 'ADD COLUMN admission_process TEXT DEFAULT NULL',
        'placement_info' => 'ADD COLUMN placement_info TEXT DEFAULT NULL',
        'about_college' => 'ADD COLUMN about_college TEXT DEFAULT NULL',
    ];

    foreach ($required as $column => $definition) {
        if (!isset($columns[$column])) {
            $conn->query('ALTER TABLE colleges ' . $definition);
        }
    }

    $conn->query('ALTER TABLE colleges MODIFY facebook VARCHAR(255) NOT NULL DEFAULT ""');
    $conn->query('ALTER TABLE colleges MODIFY instagram VARCHAR(255) NOT NULL DEFAULT ""');
    $conn->query('ALTER TABLE colleges MODIFY twitter VARCHAR(255) NOT NULL DEFAULT ""');
    $conn->query('ALTER TABLE colleges MODIFY linkedin VARCHAR(255) NOT NULL DEFAULT ""');
    $conn->query('ALTER TABLE colleges MODIFY fees VARCHAR(255) DEFAULT NULL');

    $indexes = [];
    $indexResult = $conn->query('SHOW INDEX FROM colleges');
    while ($row = $indexResult->fetch_assoc()) {
        $indexes[$row['Key_name']] = true;
    }

    if (!isset($indexes['uq_colleges_slug'])) {
        $conn->query('CREATE UNIQUE INDEX uq_colleges_slug ON colleges (slug)');
    }

    if (!isset($indexes['idx_colleges_status'])) {
        $conn->query('CREATE INDEX idx_colleges_status ON colleges (scrape_status)');
    }

    $done = true;
}

function ensureLeadSchema(mysqli $conn): void
{
    ensureScraperSchema($conn);

    $conn->query(
        "CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            course_interest VARCHAR(255) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            state VARCHAR(100) DEFAULT NULL,
            latitude DECIMAL(10,8) DEFAULT NULL,
            longitude DECIMAL(11,8) DEFAULT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_students_phone (phone),
            KEY idx_students_course (course_interest),
            KEY idx_students_location (latitude, longitude)
        )"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS lead_distribution (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            college_id INT NOT NULL,
            distributed_date DATE NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'sent',
            distance_km DECIMAL(8,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_college (student_id, college_id),
            KEY idx_lead_college_date (college_id, distributed_date),
            KEY idx_lead_student (student_id),
            CONSTRAINT fk_lead_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_lead_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
        )"
    );

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM lead_distribution');
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }

    if (!isset($columns['distance_km'])) {
        $conn->query('ALTER TABLE lead_distribution ADD COLUMN distance_km DECIMAL(8,2) DEFAULT NULL');
    }

    if (!isset($columns['created_at'])) {
        $conn->query('ALTER TABLE lead_distribution ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    }

    $indexes = [];
    $indexResult = $conn->query('SHOW INDEX FROM lead_distribution');
    while ($row = $indexResult->fetch_assoc()) {
        $indexes[$row['Key_name']] = true;
    }

    if (!isset($indexes['idx_lead_college_date'])) {
        $conn->query('CREATE INDEX idx_lead_college_date ON lead_distribution (college_id, distributed_date)');
    }

    if (!isset($indexes['idx_lead_student'])) {
        $conn->query('CREATE INDEX idx_lead_student ON lead_distribution (student_id)');
    }

    if (!isset($indexes['uq_student_college'])) {
        try {
            $conn->query('CREATE UNIQUE INDEX uq_student_college ON lead_distribution (student_id, college_id)');
        } catch (mysqli_sql_exception) {
            writeLog('Skipped uq_student_college index because existing duplicate rows are present.');
        }
    }
}

function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function normalizeLeadText(?string $value, int $maxLength = 255): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

    return mb_substr($value, 0, $maxLength);
}

function normalizePhone(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
    if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
        $digits = substr($digits, -10);
    }

    return mb_substr($digits, 0, 20);
}

function geocodeStudentLocation(string $city, string $state): array
{
    $query = trim(implode(', ', array_filter([$city, $state, 'India'])));
    if ($query === 'India' || $query === '') {
        return ['lat' => null, 'lng' => null, 'source' => ''];
    }

    $config = require __DIR__ . '/../config/config.php';
    $googleKey = trim((string) ($config['apis']['google_geocoding_key'] ?? ''));

    if ($googleKey !== '') {
        $data = googleGeocode($query, $googleKey);

        if (is_array($data) && !empty($data['results'][0]['geometry']['location'])) {
            return [
                'lat' => (float) $data['results'][0]['geometry']['location']['lat'],
                'lng' => (float) $data['results'][0]['geometry']['location']['lng'],
                'source' => 'google',
            ];
        }

        if (is_array($data) && !empty($data['status']) && $data['status'] !== 'ZERO_RESULTS') {
            writeLog('Google geocoding failed for "' . $query . '": ' . (string) $data['status']);
        }
    }

    $localLocation = localIndianCityCoordinates($city, $state);
    if ($localLocation['lat'] !== null && $localLocation['lng'] !== null) {
        return $localLocation;
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($query);
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: TopCollegesLeadSystem/1.0\r\n",
            'timeout' => 8,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $data = $response !== false ? json_decode($response, true) : null;

    if (is_array($data) && !empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        return [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
            'source' => 'nominatim',
        ];
    }

    return ['lat' => null, 'lng' => null, 'source' => ''];
}

function localIndianCityCoordinates(string $city, string $state = ''): array
{
    $cityKey = strtolower(trim($city));
    $stateKey = strtolower(trim($state));
    $cityKey = preg_replace('/[^a-z0-9]+/', ' ', $cityKey) ?? '';
    $stateKey = preg_replace('/[^a-z0-9]+/', ' ', $stateKey) ?? '';
    $cityKey = trim($cityKey);
    $stateKey = trim($stateKey);

    $locations = [
        'delhi|delhi' => [28.6138954, 77.2090057],
        'new delhi|delhi' => [28.6138954, 77.2090057],
        'noida|uttar pradesh' => [28.5355161, 77.3910265],
        'ghaziabad|uttar pradesh' => [28.6691565, 77.4537578],
        'gurugram|haryana' => [28.4594965, 77.0266383],
        'gurgaon|haryana' => [28.4594965, 77.0266383],
        'faridabad|haryana' => [28.4089123, 77.3177894],
        'sonipat|haryana' => [28.9930823, 77.0150735],
        'rohtak|haryana' => [28.8955152, 76.606611],
        'panipat|haryana' => [29.3909464, 76.9635023],
        'karnal|haryana' => [29.6856929, 76.9904825],
        'ambala|haryana' => [30.3781788, 76.7766974],
        'hisar|haryana' => [29.1491875, 75.7216527],
        'chandigarh|chandigarh' => [30.7333148, 76.7794179],
        'mumbai|maharashtra' => [19.0759837, 72.8776559],
        'pune|maharashtra' => [18.5204303, 73.8567437],
        'nagpur|maharashtra' => [21.1458004, 79.0881546],
        'bengaluru|karnataka' => [12.9715987, 77.5945627],
        'bangalore|karnataka' => [12.9715987, 77.5945627],
        'mysuru|karnataka' => [12.2958104, 76.6393805],
        'mangalore|karnataka' => [12.9141417, 74.8559568],
        'hyderabad|telangana' => [17.385044, 78.486671],
        'chennai|tamil nadu' => [13.0843007, 80.2704622],
        'coimbatore|tamil nadu' => [11.0168445, 76.9558321],
        'madurai|tamil nadu' => [9.9252007, 78.1197754],
        'kolkata|west bengal' => [22.572646, 88.363895],
        'jaipur|rajasthan' => [26.9124336, 75.7872709],
        'jodhpur|rajasthan' => [26.2389469, 73.0243094],
        'udaipur|rajasthan' => [24.585445, 73.712479],
        'lucknow|uttar pradesh' => [26.8466937, 80.946166],
        'kanpur|uttar pradesh' => [26.449923, 80.3318736],
        'agra|uttar pradesh' => [27.1766701, 78.0080745],
        'varanasi|uttar pradesh' => [25.3176452, 82.9739144],
        'meerut|uttar pradesh' => [28.9844618, 77.7064137],
        'prayagraj|uttar pradesh' => [25.4358011, 81.846311],
        'allahabad|uttar pradesh' => [25.4358011, 81.846311],
        'ahmedabad|gujarat' => [23.022505, 72.5713621],
        'surat|gujarat' => [21.1702401, 72.8310607],
        'vadodara|gujarat' => [22.3071588, 73.1812187],
        'indore|madhya pradesh' => [22.7195687, 75.8577258],
        'bhopal|madhya pradesh' => [23.2599333, 77.412615],
        'gwalior|madhya pradesh' => [26.2182871, 78.1828308],
        'patna|bihar' => [25.5940947, 85.1375645],
        'ranchi|jharkhand' => [23.3440997, 85.309562],
        'bhubaneswar|odisha' => [20.2960587, 85.8245398],
        'raipur|chhattisgarh' => [21.2513844, 81.6296413],
        'dehradun|uttarakhand' => [30.3164945, 78.0321918],
        'kochi|kerala' => [9.9312328, 76.2673041],
        'thiruvananthapuram|kerala' => [8.5241391, 76.9366376],
        'amritsar|punjab' => [31.634, 74.8723],
        'jalandhar|punjab' => [31.3260152, 75.5761829],
    ];

    $exactKey = $cityKey . '|' . $stateKey;
    if (isset($locations[$exactKey])) {
        return ['lat' => $locations[$exactKey][0], 'lng' => $locations[$exactKey][1], 'source' => 'local_free'];
    }

    foreach ($locations as $key => $coords) {
        [$knownCity] = explode('|', $key, 2);
        if ($knownCity === $cityKey) {
            return ['lat' => $coords[0], 'lng' => $coords[1], 'source' => 'local_free'];
        }
    }

    return ['lat' => null, 'lng' => null, 'source' => ''];
}

function nearbyKnownAreas(float $latitude, float $longitude, float $radiusKm = 10): array
{
    $nearby = [];
    foreach (knownIndianAreas() as $area) {
        $distance = distanceKm($latitude, $longitude, (float) $area['lat'], (float) $area['lng']);
        if ($distance <= $radiusKm) {
            $area['distance_km'] = round($distance, 2);
            $nearby[] = $area;
        }
    }

    usort($nearby, static fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);

    return $nearby;
}

function allAreasWithinRadius(float $latitude, float $longitude, float $radiusKm = 10): array
{
    $areas = nearbyKnownAreas($latitude, $longitude, $radiusKm);
    $osmAreas = overpassAreasWithinRadius($latitude, $longitude, $radiusKm);

    foreach ($osmAreas as $area) {
        $key = strtolower((string) $area['name']) . '|' . strtolower((string) $area['city']);
        $exists = false;
        foreach ($areas as $existing) {
            $existingKey = strtolower((string) $existing['name']) . '|' . strtolower((string) $existing['city']);
            if ($existingKey === $key) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $areas[] = $area;
        }
    }

    usort($areas, static fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);

    return $areas;
}

function overpassAreasWithinRadius(float $latitude, float $longitude, float $radiusKm = 10): array
{
    $radiusMeters = max(1, (int) round($radiusKm * 1000));
    $query = '[out:json][timeout:12];('
        . 'node(around:' . $radiusMeters . ',' . $latitude . ',' . $longitude . ')["place"~"^(suburb|neighbourhood|quarter|locality|town|village|city)$"]["name"];'
        . ');out tags center;';

    $url = 'https://overpass-api.de/api/interpreter?data=' . urlencode($query);
    $response = httpGet($url, 15, 'TopCollegesLeadSystem/1.0');
    if ($response === null) {
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['elements']) || !is_array($data['elements'])) {
        return [];
    }

    $areas = [];
    $seen = [];
    foreach ($data['elements'] as $element) {
        if (!is_array($element) || empty($element['tags']['name'])) {
            continue;
        }

        $areaLat = isset($element['lat']) ? (float) $element['lat'] : (float) ($element['center']['lat'] ?? 0);
        $areaLng = isset($element['lon']) ? (float) $element['lon'] : (float) ($element['center']['lon'] ?? 0);
        if ($areaLat === 0.0 && $areaLng === 0.0) {
            continue;
        }

        $name = normalizeLeadText((string) $element['tags']['name'], 150);
        if ($name === '') {
            continue;
        }

        $distance = round(distanceKm($latitude, $longitude, $areaLat, $areaLng), 2);
        if ($distance > $radiusKm) {
            continue;
        }

        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $areas[] = [
            'name' => $name,
            'city' => normalizeLeadText((string) ($element['tags']['addr:city'] ?? $element['tags']['is_in:city'] ?? ''), 100),
            'state' => normalizeLeadText((string) ($element['tags']['addr:state'] ?? $element['tags']['is_in:state'] ?? ''), 100),
            'lat' => $areaLat,
            'lng' => $areaLng,
            'distance_km' => $distance,
            'source' => 'openstreetmap',
        ];
    }

    return $areas;
}

function httpGet(string $url, int $timeout = 12, string $userAgent = 'TopCollegesLeadSystem/1.0'): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $userAgent,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $status < 400 ? (string) $response : null;
    }

    $context = stream_context_create([
        'http' => [
            'header' => 'User-Agent: ' . $userAgent . "\r\n",
            'timeout' => $timeout,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);

    return $response !== false ? (string) $response : null;
}

function famousAreasByNearestCity(float $latitude, float $longitude, int $limit = 30): array
{
    $areas = knownIndianAreas();
    foreach ($areas as &$area) {
        $area['distance_km'] = round(distanceKm($latitude, $longitude, (float) $area['lat'], (float) $area['lng']), 2);
    }
    unset($area);

    usort($areas, static fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);

    if ($areas === []) {
        return [];
    }

    $nearestCity = (string) $areas[0]['city'];
    $sameCity = array_values(array_filter($areas, static fn (array $area): bool => (string) $area['city'] === $nearestCity));

    return array_slice($sameCity, 0, $limit);
}

function knownIndianAreas(): array
{
    return [
        ['name' => 'Connaught Place', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6315, 'lng' => 77.2167],
        ['name' => 'India Gate', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6129, 'lng' => 77.2295],
        ['name' => 'Chandni Chowk', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6506, 'lng' => 77.2303],
        ['name' => 'Karol Bagh', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6514, 'lng' => 77.1907],
        ['name' => 'Rajouri Garden', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6425, 'lng' => 77.1209],
        ['name' => 'Janakpuri', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6219, 'lng' => 77.0878],
        ['name' => 'Lajpat Nagar', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5677, 'lng' => 77.2433],
        ['name' => 'South Extension', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5689, 'lng' => 77.2205],
        ['name' => 'Hauz Khas', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5494, 'lng' => 77.2001],
        ['name' => 'Green Park', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5587, 'lng' => 77.2020],
        ['name' => 'Saket', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5245, 'lng' => 77.2066],
        ['name' => 'Dwarka', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5921, 'lng' => 77.0460],
        ['name' => 'Rohini', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.7495, 'lng' => 77.0565],
        ['name' => 'Pitampura', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.7033, 'lng' => 77.1322],
        ['name' => 'Vasant Kunj', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5200, 'lng' => 77.1587],
        ['name' => 'Mayur Vihar', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6086, 'lng' => 77.2956],
        ['name' => 'Preet Vihar', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.6375, 'lng' => 77.2926],
        ['name' => 'Nehru Place', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5485, 'lng' => 77.2513],
        ['name' => 'Greater Kailash', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5415, 'lng' => 77.2380],
        ['name' => 'Okhla', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.5355, 'lng' => 77.2670],
        ['name' => 'Narela', 'city' => 'Delhi', 'state' => 'Delhi', 'lat' => 28.8527, 'lng' => 77.0929],
        ['name' => 'Noida Sector 18', 'city' => 'Noida', 'state' => 'Uttar Pradesh', 'lat' => 28.5708, 'lng' => 77.3261],
        ['name' => 'Noida Sector 62', 'city' => 'Noida', 'state' => 'Uttar Pradesh', 'lat' => 28.6279, 'lng' => 77.3649],
        ['name' => 'Greater Noida', 'city' => 'Noida', 'state' => 'Uttar Pradesh', 'lat' => 28.4744, 'lng' => 77.5030],
        ['name' => 'Ghaziabad', 'city' => 'Ghaziabad', 'state' => 'Uttar Pradesh', 'lat' => 28.6692, 'lng' => 77.4538],
        ['name' => 'Vaishali', 'city' => 'Ghaziabad', 'state' => 'Uttar Pradesh', 'lat' => 28.6420, 'lng' => 77.3382],
        ['name' => 'Indirapuram', 'city' => 'Ghaziabad', 'state' => 'Uttar Pradesh', 'lat' => 28.6415, 'lng' => 77.3714],
        ['name' => 'Gurugram', 'city' => 'Gurugram', 'state' => 'Haryana', 'lat' => 28.4595, 'lng' => 77.0266],
        ['name' => 'Cyber City', 'city' => 'Gurugram', 'state' => 'Haryana', 'lat' => 28.4949, 'lng' => 77.0898],
        ['name' => 'Sohna Road', 'city' => 'Gurugram', 'state' => 'Haryana', 'lat' => 28.4089, 'lng' => 77.0429],
        ['name' => 'Faridabad', 'city' => 'Faridabad', 'state' => 'Haryana', 'lat' => 28.4089, 'lng' => 77.3178],
        ['name' => 'Ballabhgarh', 'city' => 'Faridabad', 'state' => 'Haryana', 'lat' => 28.3415, 'lng' => 77.3256],
        ['name' => 'Mumbai Central', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 18.9690, 'lng' => 72.8205],
        ['name' => 'Colaba', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 18.9067, 'lng' => 72.8147],
        ['name' => 'Dadar', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 19.0178, 'lng' => 72.8478],
        ['name' => 'Andheri', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 19.1197, 'lng' => 72.8468],
        ['name' => 'Bandra', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 19.0596, 'lng' => 72.8295],
        ['name' => 'Powai', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 19.1176, 'lng' => 72.9060],
        ['name' => 'Thane', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'lat' => 19.2183, 'lng' => 72.9781],
        ['name' => 'Shivajinagar', 'city' => 'Pune', 'state' => 'Maharashtra', 'lat' => 18.5308, 'lng' => 73.8475],
        ['name' => 'Kothrud', 'city' => 'Pune', 'state' => 'Maharashtra', 'lat' => 18.5074, 'lng' => 73.8077],
        ['name' => 'Hinjewadi', 'city' => 'Pune', 'state' => 'Maharashtra', 'lat' => 18.5913, 'lng' => 73.7389],
        ['name' => 'Viman Nagar', 'city' => 'Pune', 'state' => 'Maharashtra', 'lat' => 18.5679, 'lng' => 73.9143],
        ['name' => 'MG Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'lat' => 12.9759, 'lng' => 77.6069],
        ['name' => 'Indiranagar', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'lat' => 12.9784, 'lng' => 77.6408],
        ['name' => 'Koramangala', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'lat' => 12.9352, 'lng' => 77.6245],
        ['name' => 'Whitefield', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'lat' => 12.9698, 'lng' => 77.7500],
        ['name' => 'Jayanagar', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'lat' => 12.9250, 'lng' => 77.5938],
        ['name' => 'Banjara Hills', 'city' => 'Hyderabad', 'state' => 'Telangana', 'lat' => 17.4126, 'lng' => 78.4482],
        ['name' => 'Secunderabad', 'city' => 'Hyderabad', 'state' => 'Telangana', 'lat' => 17.4399, 'lng' => 78.4983],
        ['name' => 'HITEC City', 'city' => 'Hyderabad', 'state' => 'Telangana', 'lat' => 17.4435, 'lng' => 78.3772],
        ['name' => 'Gachibowli', 'city' => 'Hyderabad', 'state' => 'Telangana', 'lat' => 17.4401, 'lng' => 78.3489],
        ['name' => 'T Nagar', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'lat' => 13.0418, 'lng' => 80.2341],
        ['name' => 'Anna Nagar', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'lat' => 13.0850, 'lng' => 80.2101],
        ['name' => 'Adyar', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'lat' => 13.0067, 'lng' => 80.2578],
        ['name' => 'Velachery', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'lat' => 12.9791, 'lng' => 80.2209],
        ['name' => 'Salt Lake', 'city' => 'Kolkata', 'state' => 'West Bengal', 'lat' => 22.5867, 'lng' => 88.4171],
        ['name' => 'Park Street', 'city' => 'Kolkata', 'state' => 'West Bengal', 'lat' => 22.5532, 'lng' => 88.3508],
        ['name' => 'Howrah', 'city' => 'Kolkata', 'state' => 'West Bengal', 'lat' => 22.5958, 'lng' => 88.2636],
        ['name' => 'New Town', 'city' => 'Kolkata', 'state' => 'West Bengal', 'lat' => 22.5786, 'lng' => 88.4793],
        ['name' => 'C Scheme', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'lat' => 26.9124, 'lng' => 75.8060],
        ['name' => 'Malviya Nagar', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'lat' => 26.8548, 'lng' => 75.8060],
        ['name' => 'Gomti Nagar', 'city' => 'Lucknow', 'state' => 'Uttar Pradesh', 'lat' => 26.8560, 'lng' => 81.0000],
        ['name' => 'Hazratganj', 'city' => 'Lucknow', 'state' => 'Uttar Pradesh', 'lat' => 26.8500, 'lng' => 80.9450],
    ];
}

function googleGeocode(string $query, string $apiKey): ?array
{
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
        . urlencode($query)
        . '&key='
        . urlencode($apiKey);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TopCollegesLeadSystem/1.0',
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            writeLog('Google geocoding HTTP error for "' . $query . '": ' . ($error ?: 'HTTP ' . $status));
            return null;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }

    $response = @file_get_contents($url);
    $decoded = $response !== false ? json_decode($response, true) : null;

    return is_array($decoded) ? $decoded : null;
}

function courseMatchesCollege(string $studentCourse, array $college): bool
{
    $studentCourse = mb_strtolower(trim($studentCourse));
    $studentNeedle = preg_replace('/[^a-z0-9]+/', '', $studentCourse) ?? '';
    if ($studentNeedle === '') {
        return false;
    }

    $haystack = mb_strtolower(
        implode(' ', array_filter([
            (string) ($college['courses'] ?? ''),
            (string) ($college['course_name'] ?? ''),
            (string) ($college['description'] ?? ''),
            (string) ($college['seo_keywords'] ?? ''),
        ]))
    );

    $normalizedHaystack = preg_replace('/[^a-z0-9]+/', '', $haystack) ?? '';

    return $normalizedHaystack !== '' && str_contains($normalizedHaystack, $studentNeedle);
}

function removeDuplicateStudents(mysqli $conn): int
{
    ensureLeadSchema($conn);

    $conn->query(
        "DELETE s1 FROM students s1
         INNER JOIN students s2
            ON s1.id > s2.id
           AND s1.phone IS NOT NULL
           AND s1.phone <> ''
           AND s1.phone = s2.phone"
    );
    $removedByPhone = $conn->affected_rows;

    $conn->query(
        "DELETE s1 FROM students s1
         INNER JOIN students s2
            ON s1.id > s2.id
           AND s1.email IS NOT NULL
           AND s1.email <> ''
           AND s1.email = s2.email"
    );

    return max(0, $removedByPhone) + max(0, $conn->affected_rows);
}

function distributeStudentLeads(mysqli $conn, int $radiusKm = 50, int $dailyLimit = 5): array
{
    ensureLeadSchema($conn);

    $today = date('Y-m-d');
    $stats = [
        'checked' => 0,
        'sent' => 0,
        'skipped_no_location' => 0,
        'skipped_no_match' => 0,
        'skipped_existing' => 0,
    ];

    $students = $conn->query(
        "SELECT s.*
         FROM students s
         WHERE s.latitude IS NOT NULL
           AND s.longitude IS NOT NULL
           AND s.course_interest IS NOT NULL
           AND s.course_interest <> ''
         ORDER BY s.uploaded_at DESC, s.id DESC"
    );

    $collegesResult = $conn->query(
        "SELECT id, COALESCE(NULLIF(college_name,''), clg_name) AS college_name, email, city, state,
                latitude, longitude, courses, course_name, description, seo_keywords
         FROM colleges
         WHERE latitude IS NOT NULL
           AND longitude IS NOT NULL"
    );
    $colleges = $collegesResult->fetch_all(MYSQLI_ASSOC);

    while ($student = $students->fetch_assoc()) {
        $stats['checked']++;
        $studentId = (int) $student['id'];

        $existingStmt = $conn->prepare('SELECT id FROM lead_distribution WHERE student_id = ? LIMIT 1');
        $existingStmt->bind_param('i', $studentId);
        $existingStmt->execute();
        $alreadyDistributed = $existingStmt->get_result()->num_rows > 0;
        $existingStmt->close();

        if ($alreadyDistributed) {
            $stats['skipped_existing']++;
            continue;
        }

        $studentLat = (float) $student['latitude'];
        $studentLng = (float) $student['longitude'];
        if ($studentLat === 0.0 && $studentLng === 0.0) {
            $stats['skipped_no_location']++;
            continue;
        }

        $candidates = [];
        foreach ($colleges as $college) {
            if (!courseMatchesCollege((string) $student['course_interest'], $college)) {
                continue;
            }

            $distance = distanceKm($studentLat, $studentLng, (float) $college['latitude'], (float) $college['longitude']);
            if ($distance <= $radiusKm) {
                $college['distance_km'] = round($distance, 2);
                $candidates[] = $college;
            }
        }

        if ($candidates === []) {
            $stats['skipped_no_match']++;
            continue;
        }

        shuffle($candidates);

        foreach ($candidates as $college) {
            $collegeId = (int) $college['id'];
            $countStmt = $conn->prepare(
                'SELECT COUNT(*) AS total FROM lead_distribution WHERE college_id = ? AND distributed_date = ?'
            );
            $countStmt->bind_param('is', $collegeId, $today);
            $countStmt->execute();
            $leadCount = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();

            if ($leadCount >= $dailyLimit) {
                continue;
            }

            $status = 'sent';
            $distance = (float) $college['distance_km'];
            $insertStmt = $conn->prepare(
                'INSERT IGNORE INTO lead_distribution (student_id, college_id, distributed_date, status, distance_km)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertStmt->bind_param('iissd', $studentId, $collegeId, $today, $status, $distance);
            $insertStmt->execute();

            if ($insertStmt->affected_rows > 0) {
                $stats['sent']++;
                $insertStmt->close();
                break;
            }

            $insertStmt->close();
        }
    }

    return $stats;
}

function resolvePythonBinary(): string
{
    $config = require __DIR__ . '/../config/config.php';
    $configured = trim((string) ($config['app']['python_path'] ?? ''));

    $candidates = array_values(array_unique(array_filter([
        $configured,
        'python',
        'py',
        'C:\\Python312\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python310\\python.exe',
    ])));

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }

        if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
            $output = [];
            $exitCode = 1;
            exec('where ' . escapeshellarg($candidate) . ' 2>nul', $output, $exitCode);
            if ($exitCode === 0 && isset($output[0]) && is_file(trim($output[0]))) {
                return trim($output[0]);
            }
        }
    }

    return '';
}

function getCollegeNameById(mysqli $conn, int $collegeId): string
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(college_name,''), NULLIF(clg_name,'')) AS college_name
         FROM colleges WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return trim((string) ($row['college_name'] ?? ''));
}

function markCollegeScrapeFailed(mysqli $conn, int $collegeId, string $reason): void
{
    $stmt = $conn->prepare("UPDATE colleges SET scrape_status='failed', scrape_error=?, scrape_started_at=NULL WHERE id=?");
    $stmt->bind_param('si', $reason, $collegeId);
    $stmt->execute();
    $stmt->close();
    writeLog($reason);
}

function queueCollegeScraper(mysqli $conn, int $collegeId, string $collegeName = '', bool $background = true): array
{
    if ($collegeId <= 0) {
        return ['ok' => false, 'error' => 'Invalid college id.'];
    }

    if ($collegeName === '') {
        $collegeName = getCollegeNameById($conn, $collegeId);
    }

    $collegeName = trim(preg_replace('/\s+/', ' ', $collegeName) ?? '');

    if ($collegeName === '' || mb_strlen($collegeName) < 2) {
        return ['ok' => false, 'error' => 'College name is missing for scraping.'];
    }

    if (mb_strlen($collegeName) > 255) {
        return ['ok' => false, 'error' => 'College name is too long (max 255 characters).'];
    }

    $pythonBinary = resolvePythonBinary();
    $script = realpath(__DIR__ . '/../scraper/scraper.py');
    $logDir = realpath(__DIR__ . '/../logs') ?: (__DIR__ . '/../logs');
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'background-scraper.log';

    if ($pythonBinary === '') {
        $fail = 'Python not found. Install Python 3 and add it to PATH, or set app.python_path in config/config.php';
        markCollegeScrapeFailed($conn, $collegeId, $fail);

        return ['ok' => false, 'error' => $fail];
    }

    if ($script === false) {
        $fail = 'Python scraper file not found.';
        markCollegeScrapeFailed($conn, $collegeId, $fail);

        return ['ok' => false, 'error' => $fail];
    }

    if (!function_exists('exec') && !function_exists('popen')) {
        $fail = 'PHP exec/popen is disabled. Scraper cannot start automatically.';
        markCollegeScrapeFailed($conn, $collegeId, $fail);

        return ['ok' => false, 'error' => $fail];
    }

    $command = escapeshellarg($pythonBinary)
        . ' ' . escapeshellarg($script)
        . ' ' . $collegeId
        . ' ' . escapeshellarg($collegeName);

    writeLog('Queued scraper college ' . $collegeId . ' (' . $collegeName . '): ' . $command);

    if ($background) {
        if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
            $backgroundCommand = 'start "" /B cmd /C "' . $command . ' >> ' . escapeshellarg($logFile) . ' 2>&1"';
            pclose(popen($backgroundCommand, 'r'));
        } else {
            exec($command . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
        }

        return ['ok' => true, 'error' => ''];
    }

    exec($command . ' 2>&1', $output, $exitCode);
    foreach ($output as $line) {
        writeLog('Python scraper college ' . $collegeId . ': ' . $line);
    }

    if ($exitCode !== 0) {
        $scrapeError = '';
        $stmt = $conn->prepare('SELECT scrape_error FROM colleges WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $collegeId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $scrapeError = trim((string) ($row['scrape_error'] ?? ''));
        }

        if ($scrapeError === '' && !empty($output)) {
            $scrapeError = trim((string) end($output));
        }

        return [
            'ok' => false,
            'error' => $scrapeError !== ''
                ? 'Scraping failed: ' . $scrapeError
                : 'Scraping failed. Check logs/python-scraper.log',
        ];
    }

    return ['ok' => true, 'error' => ''];
}

function writeLog(string $message): void
{
    $config = require __DIR__ . '/../config/config.php';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($config['app']['log_file'], $line, FILE_APPEND | LOCK_EX);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
