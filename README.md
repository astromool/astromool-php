# astromool/astromool-php

Official client for the [AstroMool API](https://developers.astromool.com/docs): Vedic charts computed from the
Swiss Ephemeris, the 90 astromool.com calculators, and the full PDF reports. PHP 7.4+ with ext-curl and ext-json.

```php
use AstroMool\AstroMool;
use AstroMool\AstroMoolException;

$am = new AstroMool(getenv('ASTROMOOL_KEY'));   // am_test_... while you build, am_live_... in production

$birth = ['date' => '1995-08-14', 'time' => '10:35', 'tz_id' => 'Asia/Kolkata', 'lat' => 25.5941, 'lon' => 85.1376];
$chart = $am->planets($birth);                  // ['data' => ..., 'meta' => ...]
echo $chart['data']['ascendant']['sign'];

$m = $am->runCalculator('manglik-dosha-calculator-by-date-of-birth', $birth);
echo $m['data']['summary'];                     // "Manglik - intensity 18/100"
```

Without Composer: `require` `src/AstroMoolException.php`, `src/AstroMool.php` and `src/Webhook.php`.

## Methods

| Method | API |
|---|---|
| `planets($birth, $ayanamsa, $node)` | `POST /v1/vedic/planets` |
| `calculators()` / `runCalculator($slug, $person, ['partner'=>, 'params'=>, 'detail'=>])` | `/v1/calculators` |
| `createReport($slug, $person, ['partner'=>, 'lang'=>, 'webhook_url'=>, 'idempotency_key'=>])` | `POST /v1/reports/{slug}` |
| `getReport($id)` / `waitForReport($id, 300)` / `downloadReport($url)` | |
| `geoSearch($q, $limit)` / `timezone($tzId, $date, $time)` | `/v1/geo/*` |
| `ayanamsas($date)`, `usage()`, `health()` | |

## Charts, dasha, panchang, matching and more (1.1.0)

```php
$b = ['date' => '1995-08-14', 'time' => '10:35', 'place' => 'Patna'];   // or lat/lon/tz_id; 'birth_time_unknown' => true for a noon chart
$am->chart($b); $am->divisional($b, 'D9'); $am->ashtakavarga($b);
$svg = $am->chartImage($b, ['style' => 'south', 'chart' => 'D9']);    // 'format' => 'png' for PNG bytes
$am->vimshottari($b); $am->yogini($b);
$am->panchang(['place' => 'Delhi'], '2026-10-11');                   // 3rd argument: 'rahu-kaal' | 'choghadiya' | 'hora'
$am->ashtakoot($groom, $bride); $am->numerology('Asha Verma', '1995-08-14');
$am->dosha('mangal', $b); $am->remedy('gemstone', $b);
$am->transitNatal($b); $am->kpChart($b); $am->westernNatal($b);
$am->batch([['path' => '/v1/vedic/houses', 'body' => $b], ['method' => 'GET', 'path' => '/v1/usage']]);
$am->topic('/v1/muhurat/vivah', ['person' => ['place' => 'Delhi'], 'params' => ['year' => 2027]]);   // any topic endpoint
```

## Reports

```php
$r = $am->createReport('kundli-matching-by-date-of-birth', $groom, ['partner' => $bride, 'lang' => 'both']);
$ready = $am->waitForReport($r['data']['report_id']);
file_put_contents('report.pdf', $am->downloadReport($ready['data']['download_url']));
```

## Errors and retries

Failures throw `AstroMoolException` with `status`, `errorCode` (stable - see the
[errors page](https://developers.astromool.com/errors)), `detail`, `requestId` and `field`. The client retries
`rate_limited` (waiting `Retry-After`), 5xx answers and network errors (`max_retries` 2).

## Webhooks

```php
$event = \AstroMool\Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_X_ASTROMOOL_SIGNATURE'] ?? '', getenv('ASTROMOOL_WEBHOOK_SECRET'));
```

The webhook secret is in the dashboard under Keys.

MIT licence.
