<?php

declare(strict_types=1);

final class CollegeScraper
{
    private mysqli $conn;
    private array $config;

    public function __construct(mysqli $conn, array $config)
    {
        $this->conn = $conn;
        $this->config = $config;
    }

    public function scrapeByCollegeId(int $collegeId): void
    {
        $college = $this->getCollege($collegeId);

        if ($college === null) {
            throw new RuntimeException('College not found: ' . $collegeId);
        }

        $this->markStatus($collegeId, 'running', null);

        try {
            $collegeName = (string) ($college['clg_name'] ?? '');
            $website = $college['website'] ?: $this->findOfficialWebsite($collegeName);

            if ($website === '') {
                throw new RuntimeException('Official website not found.');
            }

            $html = $this->fetchUrl($website);
            $plainText = $this->cleanText($html);
            $emails = $this->extractEmails($plainText);
            $phones = $this->extractPhones($plainText);
            $courses = $this->extractCourses($plainText);
            $address = $this->extractAddress($plainText);
            $coordinates = $this->geocode($collegeName . ' ' . ($address ?: ''));
            $location = $this->extractLocation($plainText, $address);
            $seo = $this->generateSeoContent($collegeName, $website, $plainText, $courses, $address, $location);

            $shortDescription = $this->summary($plainText, 280);
            $longDescription = mb_substr($plainText, 0, 5000);
            $email = $emails[0] ?? null;
            $phone = $phones[0] ?? null;
            $courseText = $courses !== [] ? implode(', ', $courses) : null;

            $this->saveScrapedData(
                $collegeId,
                $collegeName,
                $website,
                $email,
                $phone,
                $address,
                $location['city'] ?? null,
                $location['state'] ?? null,
                $coordinates['lat'] ?? null,
                $coordinates['lng'] ?? null,
                $courseText,
                $shortDescription,
                $longDescription,
                $seo
            );
        } catch (Throwable $exception) {
            $this->markStatus($collegeId, 'failed', $exception->getMessage());
            throw $exception;
        }
    }

    private function getCollege(int $collegeId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM colleges WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $collegeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ?: null;
    }

    private function findOfficialWebsite(string $collegeName): string
    {
        $serpApiKey = trim((string) $this->config['apis']['serpapi_key']);

        if ($serpApiKey !== '') {
            $website = $this->searchWithSerpApi($collegeName, $serpApiKey);
            if ($website !== '') {
                return $website;
            }
        }

        return $this->searchWithDuckDuckGo($collegeName);
    }

    private function searchWithSerpApi(string $collegeName, string $apiKey): string
    {
        $url = 'https://serpapi.com/search.json?engine=google&q=' . urlencode($collegeName . ' official website') . '&api_key=' . urlencode($apiKey);
        $json = $this->fetchUrl($url);
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['organic_results'])) {
            return '';
        }

        foreach ($data['organic_results'] as $result) {
            $link = (string) ($result['link'] ?? '');
            if ($this->isAllowedWebsite($link)) {
                return $link;
            }
        }

        return '';
    }

    private function searchWithDuckDuckGo(string $collegeName): string
    {
        $url = 'https://duckduckgo.com/html/?q=' . urlencode($collegeName . ' official website');
        $html = $this->fetchUrl($url);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//a[contains(@class, "result__a")]/@href');

        if ($links === false) {
            return '';
        }

        foreach ($links as $linkNode) {
            $link = html_entity_decode($linkNode->nodeValue, ENT_QUOTES, 'UTF-8');
            $link = $this->normalizeSearchRedirect($link);

            if ($this->isAllowedWebsite($link)) {
                return $link;
            }
        }

        return '';
    }

    private function normalizeSearchRedirect(string $url): string
    {
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['uddg'])) {
                return (string) $query['uddg'];
            }
        }

        return $url;
    }

    private function isAllowedWebsite(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $blockedHosts = ['facebook.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'wikipedia.org', 'shiksha.com', 'careers360.com'];

        foreach ($blockedHosts as $blockedHost) {
            if ($host === $blockedHost || str_ends_with($host, '.' . $blockedHost)) {
                return false;
            }
        }

        return true;
    }

    private function fetchUrl(string $url): string
    {
        $error = '';
        $status = 0;
        $body = $this->curlRequest($url, true, $error, $status);

        if (($body === false || $status >= 400) && stripos($error, 'SSL') !== false) {
            $body = $this->curlRequest($url, false, $error, $status);
        }

        if ($body === false || $status >= 400) {
            throw new RuntimeException('Failed to fetch URL: ' . $url . ($error !== '' ? ' - ' . $error : ''));
        }

        return (string) $body;
    }

    private function curlRequest(string $url, bool $verifyPeer, string &$error, int &$status): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $verifyPeer ? 2 : 0,
            CURLOPT_USERAGENT => 'TopCollegesBot/1.0 (+https://topcolleges.co.in)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8'],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body;
    }

    private function cleanText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function extractEmails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function extractPhones(string $text): array
    {
        preg_match_all('/(?:\+91[\s-]?)?[6-9]\d{9}|\(?0\d{2,5}\)?[\s-]?\d{6,8}/', $text, $matches);
        $phones = array_map(static function (string $phone): string {
            return trim(preg_replace('/\s+/', ' ', $phone) ?? $phone);
        }, $matches[0] ?? []);

        return array_values(array_unique($phones));
    }

    private function extractCourses(string $text): array
    {
        $knownCourses = [
            'B.Tech', 'M.Tech', 'MBA', 'BBA', 'BCA', 'MCA', 'B.Com', 'M.Com',
            'BA', 'MA', 'B.Sc', 'M.Sc', 'LLB', 'LLM', 'MBBS', 'BDS', 'B.Pharm',
            'M.Pharm', 'B.Ed', 'M.Ed', 'PhD', 'Diploma', 'PGDM', 'BHM', 'MHM',
            'B.Arch', 'M.Arch', 'B.Des', 'M.Des', 'BPT', 'MPT', 'GNM', 'ANM',
        ];

        $found = [];
        foreach ($knownCourses as $course) {
            if (preg_match('/\b' . preg_quote($course, '/') . '\b/i', $text)) {
                $found[] = $course;
            }
        }

        return array_values(array_unique($found));
    }

    private function extractAddress(string $text): ?string
    {
        if (preg_match('/(?:address|location)\s*:?\s*(.{20,250}?)(?:phone|email|contact|admission|$)/i', $text, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function extractLocation(string $text, ?string $address): array
    {
        $source = $address ?: $text;
        $states = [
            'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
            'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
            'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Odisha',
            'Punjab', 'Rajasthan', 'Tamil Nadu', 'Telangana', 'Uttar Pradesh',
            'Uttarakhand', 'West Bengal',
        ];

        $location = ['city' => null, 'state' => null];

        foreach ($states as $state) {
            if (stripos($source, $state) !== false) {
                $location['state'] = $state;
                break;
            }
        }

        if (preg_match('/\b(New Delhi|Delhi|Mumbai|Pune|Bengaluru|Bangalore|Hyderabad|Chennai|Kolkata|Jaipur|Lucknow|Ahmedabad|Indore|Bhopal|Patna|Noida|Gurugram|Gurgaon|Chandigarh)\b/i', $source, $match)) {
            $location['city'] = $match[1];
        }

        return $location;
    }

    private function summary(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit), " \t\n\r\0\x0B.,") . '.';
    }

    private function generateSeoContent(string $collegeName, string $website, string $plainText, array $courses, ?string $address, array $location): array
    {
        $city = $location['city'] ?? '';
        $state = $location['state'] ?? '';
        $place = trim($city . ($state !== '' && $state !== $city ? ', ' . $state : ''));
        $courseText = $courses !== [] ? implode(', ', array_slice($courses, 0, 8)) : 'popular undergraduate and postgraduate courses';
        $shortSummary = $this->summary($plainText, 220);

        $titlePlace = $place !== '' ? ' in ' . $place : '';
        $seoTitle = mb_substr($collegeName . $titlePlace . ' - Courses, Fees, Admission, Contact', 0, 255);
        $seoDescription = $this->summary($collegeName . ' details including courses, admission information, fees, facilities, address, contact, website and latest overview. ' . $shortSummary, 160);
        $keywords = array_filter([
            $collegeName,
            $collegeName . ' admission',
            $collegeName . ' courses',
            $collegeName . ' fees',
            $place !== '' ? $collegeName . ' ' . $place : null,
            'college details',
        ]);

        $content = [];
        $content[] = $collegeName . ' is listed with automatically collected details from its official web presence.';
        $content[] = 'Students can review available information about courses such as ' . $courseText . ', along with contact details, location, facilities and admission-related references.';
        if ($address !== null && $address !== '') {
            $content[] = 'Address: ' . $address;
        }
        $content[] = 'Official website: ' . $website;
        $content[] = 'This SEO profile is generated from public college information and should be reviewed by an admin before publishing.';

        return [
            'title' => $seoTitle,
            'description' => $seoDescription,
            'keywords' => implode(', ', $keywords),
            'content' => implode("\n\n", $content),
        ];
    }

    private function geocode(string $address): array
    {
        $apiKey = trim((string) $this->config['apis']['google_geocoding_key']);

        if ($apiKey === '' || trim($address) === '') {
            return [];
        }

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . urlencode($apiKey);
        $json = $this->fetchUrl($url);
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['results'][0]['geometry']['location'])) {
            return [];
        }

        return [
            'lat' => (float) $data['results'][0]['geometry']['location']['lat'],
            'lng' => (float) $data['results'][0]['geometry']['location']['lng'],
        ];
    }

    private function saveScrapedData(
        int $collegeId,
        string $collegeName,
        string $website,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $city,
        ?string $state,
        ?float $latitude,
        ?float $longitude,
        ?string $courses,
        string $shortDescription,
        string $longDescription,
        array $seo
    ): void {
        $status = 'completed';
        $seoTitle = $seo['title'];
        $seoDescription = $seo['description'];
        $seoKeywords = $seo['keywords'];
        $seoContent = $seo['content'];

        $stmt = $this->conn->prepare(
            'UPDATE colleges
             SET title = ?, heading = ?, short_description = ?, long_description = ?,
                 website = ?, email = ?, phone = ?, address = ?, city = COALESCE(?, city),
                 state = COALESCE(?, state), latitude = ?, longitude = ?, course_name = COALESCE(?, course_name),
                 seo_title = ?, seo_description = ?, seo_keywords = ?, seo_content = ?,
                 scrape_status = ?, scrape_error = NULL, scraped_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssssssssssddssssssi',
            $seoTitle,
            $collegeName,
            $shortDescription,
            $longDescription,
            $website,
            $email,
            $phone,
            $address,
            $city,
            $state,
            $latitude,
            $longitude,
            $courses,
            $seoTitle,
            $seoDescription,
            $seoKeywords,
            $seoContent,
            $status,
            $collegeId
        );
        $stmt->execute();
    }

    private function markStatus(int $collegeId, string $status, ?string $error): void
    {
        $stmt = $this->conn->prepare('UPDATE colleges SET scrape_status = ?, scrape_error = ? WHERE id = ?');
        $stmt->bind_param('ssi', $status, $error, $collegeId);
        $stmt->execute();
    }
}
