<?php
declare(strict_types=1);

namespace AstroMool;

/**
 * AstroMool API client for PHP 7.4+ (ext-curl, ext-json).
 *
 *   $am = new \AstroMool\AstroMool('am_test_...');
 *   $r = $am->planets(['date' => '1995-08-14', 'time' => '10:35', 'tz_id' => 'Asia/Kolkata', 'lat' => 25.5941, 'lon' => 85.1376]);
 *   echo $r['data']['ascendant']['sign'];
 *
 * Every method returns the API's envelope as an array: ['data' => ..., 'meta' => ...]. Errors throw AstroMoolException.
 * Reference: https://developers.astromool.com/docs
 */
final class AstroMool
{
    public const VERSION = '1.1.0';
    private const RETRY_STATUS = [500, 502, 503, 504];

    /** @var string */ private $apiKey;
    /** @var string */ private $baseUrl;
    /** @var int */ private $timeout;
    /** @var int */ private $maxRetries;

    /**
     * @param string $apiKey am_test_... or am_live_... (keep it on your server)
     * @param array{base_url?:string,timeout?:int,max_retries?:int} $opts timeout in seconds; max_retries for rate_limited (waits Retry-After), 5xx and network errors
     */
    public function __construct(string $apiKey, array $opts = [])
    {
        if (!preg_match('/^am_(live|test|pub)_/', $apiKey)) {
            throw new \InvalidArgumentException('AstroMool: pass your API key (am_test_..., am_live_... or am_pub_...)');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($opts['base_url'] ?? 'https://api.astromool.com', '/');
        $this->timeout = (int)($opts['timeout'] ?? 30);
        $this->maxRetries = (int)($opts['max_retries'] ?? 2);
    }

    /** Ascendant and the nine grahas. $birth = [date, time, tz_id | utc_offset, lat, lon] */
    public function planets(array $birth, ?string $ayanamsa = null, ?string $node = null): array
    {
        return $this->request('POST', '/v1/vedic/planets', $birth + self::clean(['ayanamsa' => $ayanamsa, 'node' => $node]));
    }

    public function calculators(): array
    {
        return $this->request('GET', '/v1/calculators');
    }

    /** @param array{partner?:array,params?:array,detail?:string} $opts */
    public function runCalculator(string $slug, array $person, array $opts = []): array
    {
        return $this->request('POST', '/v1/calculators/' . rawurlencode($slug),
            self::clean(['person' => $person, 'partner' => $opts['partner'] ?? null, 'params' => $opts['params'] ?? null, 'detail' => $opts['detail'] ?? null]));
    }

    /**
     * Order a PDF report. An Idempotency-Key is generated unless given, so a retried call never creates a second report.
     * @param array{partner?:array,params?:array,lang?:string,webhook_url?:string,idempotency_key?:string} $opts
     */
    public function createReport(string $slug, array $person, array $opts = []): array
    {
        $body = self::clean(['person' => $person, 'partner' => $opts['partner'] ?? null, 'params' => $opts['params'] ?? null,
                             'lang' => $opts['lang'] ?? null, 'webhook_url' => $opts['webhook_url'] ?? null]);
        return $this->request('POST', '/v1/reports/' . rawurlencode($slug), $body, ['Idempotency-Key: ' . ($opts['idempotency_key'] ?? self::uuid())]);
    }

    public function getReport(string $reportId): array
    {
        return $this->request('GET', '/v1/reports/' . rawurlencode($reportId));
    }

    public function waitForReport(string $reportId, int $timeoutSeconds = 300, int $intervalSeconds = 2): array
    {
        $end = time() + $timeoutSeconds;
        while (true) {
            $r = $this->getReport($reportId);
            $st = $r['data']['status'];
            if ($st === 'ready') return $r;
            if ($st === 'expired') throw new AstroMoolException(410, ['code' => 'report_gone', 'detail' => 'Report files are kept for 7 days. Create the report again.']);
            if ($st === 'failed') {
                throw new AstroMoolException(422, ['code' => 'report_failed', 'title' => 'Report failed', 'request_id' => $r['meta']['request_id'] ?? null,
                    'detail' => $r['data']['error'] ?? 'The report could not be generated. It was not charged.']);
            }
            if (time() > $end) {
                throw new AstroMoolException(0, ['code' => 'timeout', 'detail' => "Report $reportId not ready after $timeoutSeconds s; it is still building - call getReport later."]);
            }
            sleep($intervalSeconds);
        }
    }

    /** The PDF bytes behind a signed download_url (no key is sent). */
    public function downloadReport(string $downloadUrl): string
    {
        [$status, $raw] = $this->curl('GET', $downloadUrl, null, []);
        if ($status !== 200) throw new AstroMoolException($status, json_decode($raw, true));
        return $raw;
    }

    public function geoSearch(string $q, ?int $limit = null): array
    {
        return $this->request('GET', '/v1/geo/search', null, [], self::clean(['q' => $q, 'limit' => $limit]));
    }

    public function timezone(string $tzId, string $date, ?string $time = null): array
    {
        return $this->request('GET', '/v1/geo/timezone', null, [], self::clean(['tz_id' => $tzId, 'date' => $date, 'time' => $time]));
    }

    public function ayanamsas(?string $date = null): array
    {
        return $this->request('GET', '/v1/meta/ayanamsas', null, [], self::clean(['date' => $date]));
    }

    public function usage(): array
    {
        return $this->request('GET', '/v1/usage');
    }

    public function health(): array
    {
        return $this->request('GET', '/v1/health');
    }

    // ---- charts, dasha, panchang, matching, numerology, doshas, remedies, transit, KP, western ($birth may use 'place' => 'Patna')
    public function chart(array $birth): array { return $this->request('POST', '/v1/vedic/chart', $birth); }
    public function houses(array $birth): array { return $this->request('POST', '/v1/vedic/houses', $birth); }
    public function ashtakavarga(array $birth): array { return $this->request('POST', '/v1/vedic/ashtakavarga', $birth); }
    /** All 16 vargas, or one: divisional($birth, 'D9') */
    public function divisional(array $birth, ?string $chart = null): array
    {
        return $this->request('POST', '/v1/vedic/divisional' . ($chart ? '/' . rawurlencode($chart) : ''), $birth);
    }
    /** The chart picture: SVG text or PNG bytes. $opts: style (north|south), chart (D1..D60), format (svg|png), theme, size */
    public function chartImage(array $birth, array $opts = []): string
    {
        $h = ['X-API-Key: ' . $this->apiKey, 'Content-Type: application/json', 'User-Agent: astromool-php/' . self::VERSION];
        [$status, $raw, $resHeaders] = $this->curl('POST', $this->baseUrl . '/v1/chart/image', json_encode($birth + $opts, JSON_THROW_ON_ERROR), $h);
        if ($status !== 200) throw new AstroMoolException($status, json_decode($raw, true), $resHeaders['x-request-id'] ?? null);
        return $raw;
    }
    public function vimshottari(array $birth): array { return $this->request('POST', '/v1/dasha/vimshottari', $birth); }
    public function yogini(array $birth): array { return $this->request('POST', '/v1/dasha/yogini', $birth); }
    /** $place: ['lat'=>, 'lon'=>, 'tz_id'=>] or ['place' => 'Patna']; $date YYYY-MM-DD (default: today there) */
    public function panchang(array $place, ?string $date = null, string $part = ''): array
    {
        return $this->request('POST', '/v1/panchang' . ($part ? '/' . $part : ''), $place + self::clean(['date' => $date]));
    }
    public function ashtakoot(array $person, array $partner): array { return $this->request('POST', '/v1/match/ashtakoot', ['person' => $person, 'partner' => $partner]); }
    public function numerology(string $name, string $date, ?string $asOf = null): array
    {
        return $this->request('POST', '/v1/numerology', self::clean(['name' => $name, 'date' => $date, 'as_of' => $asOf]));
    }
    public function dosha(string $name, array $birth): array { return $this->request('POST', '/v1/dosha/' . rawurlencode($name), $birth); }
    public function remedy(string $name, array $birth): array { return $this->request('POST', '/v1/remedies/' . rawurlencode($name), $birth); }
    public function transitPositions(array $moment = []): array { return $this->request('POST', '/v1/transit/positions', $moment); }
    public function transitNatal(array $birth, ?string $onDate = null): array { return $this->request('POST', '/v1/transit/natal', $birth + self::clean(['on_date' => $onDate])); }
    public function kpChart(array $birth): array { return $this->request('POST', '/v1/kp/chart', $birth); }
    public function kpRulingPlanets(array $moment = []): array { return $this->request('POST', '/v1/kp/ruling-planets', $moment); }
    public function kpSubTable(): array { return $this->request('GET', '/v1/kp/sub-table'); }
    public function westernNatal(array $birth): array { return $this->request('POST', '/v1/western/natal', $birth); }
    public function synastry(array $person, array $partner): array { return $this->request('POST', '/v1/western/synastry', ['person' => $person, 'partner' => $partner]); }
    public function progressions(array $birth, ?string $onDate = null): array { return $this->request('POST', '/v1/western/progressions', $birth + self::clean(['on_date' => $onDate])); }
    public function composite(array $person, array $partner): array { return $this->request('POST', '/v1/western/composite', ['person' => $person, 'partner' => $partner]); }
    /** Up to 20 calls in one request: [['method' => 'POST', 'path' => '/v1/...', 'body' => [...]], ...] */
    public function batch(array $requests): array { return $this->request('POST', '/v1/batch', ['requests' => $requests]); }
    /** Any topic endpoint, e.g. topic('/v1/muhurat/vivah', ['person' => ['place' => 'Delhi'], 'params' => ['year' => 2027]]) */
    public function topic(string $path, array $body): array { return $this->request('POST', $path, $body); }

    public function request(string $method, string $path, ?array $body = null, array $headers = [], array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $h = array_merge(['X-API-Key: ' . $this->apiKey, 'Accept: application/json', 'User-Agent: astromool-php/' . self::VERSION], $headers);
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $h[] = 'Content-Type: application/json';
        }
        for ($attempt = 0; ; $attempt++) {
            [$status, $raw, $resHeaders] = $this->curl($method, $url, $payload, $h);
            if ($status === 0) {
                if ($attempt < $this->maxRetries) { usleep((int)(500000 * 2 ** $attempt)); continue; }
                throw new AstroMoolException(0, ['code' => 'network_error', 'detail' => $raw]);
            }
            $json = json_decode($raw, true);
            if ($status >= 200 && $status < 300) return is_array($json) ? $json : [];
            $err = new AstroMoolException($status, is_array($json) ? $json : ['detail' => substr($raw, 0, 300)], $resHeaders['x-request-id'] ?? null);
            $retry = ($status === 429 && $err->errorCode === 'rate_limited') || in_array($status, self::RETRY_STATUS, true);
            if (!$retry || $attempt >= $this->maxRetries) throw $err;
            $ra = (int)($resHeaders['retry-after'] ?? $err->retryAfter ?? 1);
            if ($status === 429) sleep(min(60, max(1, $ra))); else usleep((int)(500000 * 2 ** $attempt));
        }
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function curl(string $method, string $url, ?string $payload, array $headers): array
    {
        $resHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$resHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) $resHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return [0, $error, []];
        return [$status, (string)$raw, $resHeaders];
    }

    private static function clean(array $a): array
    {
        return array_filter($a, static function ($v) { return $v !== null; });
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
