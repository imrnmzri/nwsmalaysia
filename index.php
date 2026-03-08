<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// API endpoints
$forecastApiUrl   = "https://api.data.gov.my/weather/forecast/";
$warningApiUrl    = "https://api.data.gov.my/weather/warning/";
$earthquakeApiUrl = "https://api.data.gov.my/weather/warning/earthquake";

// Klang Valley states (only config needed — town names come from locations.csv)
$klangValleyStates = ['Selangor', 'WP Kuala Lumpur', 'WP Putrajaya'];

// Load locations.csv and derive Klang Valley towns + keywords
$klangValleyTowns   = [];
$klangValleyLocNames = [];
$csvFile = __DIR__ . '/locations.csv';
if (($fh = fopen($csvFile, 'r')) !== false) {
    $header = fgetcsv($fh); // type, id, name, state
    while (($row = fgetcsv($fh)) !== false) {
        [$type, $id, $name, $state] = $row;
        if (in_array($state, $klangValleyStates)) {
            $klangValleyLocNames[] = $name;          // all types, for warning matching
            if ($type === 'Town') {
                $klangValleyTowns[] = $name;         // towns only, for forecast filtering
            }
        }
    }
    fclose($fh);
}

// Fetch data function
function fetchData($url, $ttl = 600) {
    $cacheFile = sys_get_temp_dir() . '/nwsmy_' . md5($url) . '.json';

    // Serve from cache if fresh and non-empty
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl && filesize($cacheFile) > 10) {
        $cached = file_get_contents($cacheFile);
        if ($cached) return json_decode($cached, true);
    }

    $context  = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'NWS-Malaysia/1.0']
    ]);
    $response = @file_get_contents($url, false, $context);

    // Only overwrite cache if we got a valid response
    if ($response !== false && strlen($response) > 10) {
        file_put_contents($cacheFile, $response);
        return json_decode($response, true);
    }

    // API failed — return stale cache if available, better than nothing
    if (file_exists($cacheFile) && filesize($cacheFile) > 10) {
        $cached = file_get_contents($cacheFile);
        if ($cached) return json_decode($cached, true);
    }

    return null;
}

// OCR the advisory image with Tesseract
function getAdvisoryOcrText() {
    $imageUrl  = 'https://www.met.gov.my/data/pocgn/ramalancuacasignifikan.jpg';
    $tmpImage  = sys_get_temp_dir() . '/advisory_' . md5($imageUrl) . '.jpg';
    $tmpOutput = sys_get_temp_dir() . '/advisory_ocr_' . md5($imageUrl);
    $cacheFile = $tmpOutput . '.txt';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 600 && filesize($cacheFile) > 0) {
        return file_get_contents($cacheFile);
    }

    $context   = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'NWS-Malaysia/1.0']]);
    $imageData = @file_get_contents($imageUrl, false, $context);
    if ($imageData === false || strlen($imageData) < 1000) return null;
    file_put_contents($tmpImage, $imageData);

    $tesseractPath = trim(shell_exec('which tesseract 2>/dev/null') ?: '/usr/bin/tesseract');
    if (!is_executable($tesseractPath)) return null;

    $availLangs = shell_exec($tesseractPath . ' --list-langs 2>&1') ?: '';
    $hasMsa = strpos($availLangs, 'msa') !== false;
    $hasEng = strpos($availLangs, 'eng') !== false;
    if ($hasMsa && $hasEng) { $langFlag = '-l msa+eng'; }
    elseif ($hasMsa)        { $langFlag = '-l msa'; }
    elseif ($hasEng)        { $langFlag = '-l eng'; }
    else                    { $langFlag = ''; }

    $cmd = $tesseractPath
         . ' ' . escapeshellarg($tmpImage)
         . ' ' . escapeshellarg($tmpOutput)
         . ' ' . $langFlag
         . ' --psm 6 2>/dev/null';
    exec($cmd, $out, $code);

    if ($code !== 0 || !file_exists($cacheFile) || filesize($cacheFile) === 0) return null;
    return file_get_contents($cacheFile);
}

// Extract body text and issued date from raw OCR
function parseAdvisoryOcr($rawOcr) {
    $body   = '';
    $issued = '';

    // The title "RAMALAN CUACA SIGNIFIKAN" is rendered as a graphic — Tesseract won't see it.
    // Grab everything before "Dikeluarkan" as the body.
    if (preg_match('/^(.+?)(?=Dikeluarkan)/is', $rawOcr, $m)) {
        $body = trim($m[1]);
        $body = preg_replace('/^[-\s*]+/', '', $body);   // strip leading dashes/symbols
        $body = preg_replace('/Orang awam.+$/is', '', $body); // strip trailing boilerplate
        $body = trim(preg_replace('/\s+/', ' ', $body)); // collapse whitespace
    }
    if (preg_match('/Dikeluarkan\s*:\s*.+?(?:\n|$)/i', $rawOcr, $m)) {
        $issued = trim($m[0]);
    }

    return [$body, $issued];
}

// Run advisory pipeline
$advisoryText   = '';
$advisoryIssued = '';
$rawOcr = getAdvisoryOcrText();
if ($rawOcr) {
    [$advisoryText, $advisoryIssued] = parseAdvisoryOcr($rawOcr);
}


// Map forecast text to emoji
function weatherEmoji($text) {
    $t = strtolower($text);
    if (strpos($t, 'tiada hujan') !== false) return '☀️';
    if (strpos($t, 'hujan lebat') !== false) return '⛈️';
    if (strpos($t, 'ribut petir') !== false) return '⛈️';
    if (strpos($t, 'hujan')       !== false) return '🌧️';
    if (strpos($t, 'berjerebu')   !== false) return '🌫️';
    if (strpos($t, 'berawan')     !== false) return '☁️';
    if (strpos($t, 'berpanas')    !== false) return '🌤️';
    return '';
}

// Fetch all data
$forecastData      = fetchData($forecastApiUrl);
$warningData       = fetchData($warningApiUrl);
$earthquakeDataRaw = fetchData($earthquakeApiUrl);

// Sort earthquakes by date (newest first) and get latest 10
$earthquakeData = [];
if ($earthquakeDataRaw) {
    usort($earthquakeDataRaw, function($a, $b) {
        return strtotime($b['localdatetime']) - strtotime($a['localdatetime']);
    });
    $earthquakeData = array_slice($earthquakeDataRaw, 0, 10);
}

// Filter forecasts for Klang Valley towns (Tn prefix only)
$klangValleyForecasts = [];
if ($forecastData) {
    foreach ($forecastData as $forecast) {
        if (isset($forecast['location']['location_name']) && isset($forecast['location']['location_id'])) {
            $locName = strtolower($forecast['location']['location_name']);
            $locId   = $forecast['location']['location_id'];
            if (strpos($locId, 'Tn') === 0) {
                foreach ($klangValleyTowns as $kvTown) {
                    if (strpos($locName, strtolower($kvTown)) !== false) {
                        $klangValleyForecasts[] = $forecast;
                        break;
                    }
                }
            }
        }
    }
}

// Group forecasts by location
$forecastsByLocation = [];
foreach ($klangValleyForecasts as $forecast) {
    $location = $forecast['location']['location_name'];
    if (!isset($forecastsByLocation[$location])) $forecastsByLocation[$location] = [];
    $forecastsByLocation[$location][] = $forecast;
}

// Sort each location's forecasts by date
foreach ($forecastsByLocation as $location => &$forecasts) {
    usort($forecasts, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}

// Build location-to-anchor map
$locationAnchors = [];
foreach ($forecastsByLocation as $location => $forecasts) {
    $id = 'loc-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($location));
    $locationAnchors[$location] = $id;
}

// Town coordinates for Open-Meteo current conditions
$townCoords = [
    'Kuala Lumpur'  => ['lat' => 3.1390,  'lon' => 101.6869],
    'Petaling Jaya' => ['lat' => 3.1073,  'lon' => 101.6067],
    'Shah Alam'     => ['lat' => 3.0738,  'lon' => 101.5183],
    'Klang'         => ['lat' => 3.0449,  'lon' => 101.4457],
    'Subang Jaya'   => ['lat' => 3.0565,  'lon' => 101.5851],
    'Ampang'        => ['lat' => 3.1478,  'lon' => 101.7544],
    'Cheras'        => ['lat' => 3.0804,  'lon' => 101.7493],
    'Rawang'        => ['lat' => 3.3197,  'lon' => 101.5742],
    'Sepang'        => ['lat' => 2.7360,  'lon' => 101.7013],
    'Putrajaya'     => ['lat' => 2.9264,  'lon' => 101.6964],
    'Cyberjaya'     => ['lat' => 2.9213,  'lon' => 101.6559],
    'Kajang'        => ['lat' => 2.9932,  'lon' => 101.7877],
    'Selayang'      => ['lat' => 3.2115,  'lon' => 101.6390],
    'Puchong'       => ['lat' => 3.0268,  'lon' => 101.6195],
];

// Get selected location from URL — name passed directly, no slug
$allLocationNames = array_keys($forecastsByLocation);
sort($allLocationNames);
$selectedLocation = trim($_GET['location'] ?? '');
if (!in_array($selectedLocation, $allLocationNames, true)) {
    $selectedLocation = '';
}

// Filter forecasts — all when blank, one when selected
$filteredForecasts = $selectedLocation
    ? (isset($forecastsByLocation[$selectedLocation]) ? [$selectedLocation => $forecastsByLocation[$selectedLocation]] : [])
    : $forecastsByLocation;

// Filter warnings to selected location + state-wide warnings
$filteredWarnings = [];
if ($warningData) {
    $locKeywords = array_map('strtolower', $klangValleyStates);
    if ($selectedLocation) $locKeywords[] = strtolower($selectedLocation);
    foreach ($warningData as $warning) {
        if (empty($warning['valid_from']) || empty($warning['valid_to'])) continue;
        $searchable = strtolower(implode(' ', [
            $warning['warning_issue']['title_en'] ?? '',
            $warning['warning_issue']['title_bm'] ?? '',
            $warning['heading_en'] ?? '',
            $warning['heading_bm'] ?? '',
            $warning['text_en']    ?? '',
            $warning['text_bm']    ?? '',
        ]));
        foreach ($locKeywords as $kw) {
            if (strpos($searchable, $kw) !== false) { $filteredWarnings[] = $warning; break; }
        }
    }
}

// Fetch current conditions from Open-Meteo (free, no key)
$currentTemp = $currentWeather = $currentHumidity = $currentWind = null;
$coords = null;
foreach ($townCoords as $town => $c) {
    if (stripos($selectedLocation, $town) !== false || stripos($town, $selectedLocation) !== false) {
        $coords = $c; break;
    }
}
if ($coords) {
$meteoUrl  = "https://api.open-meteo.com/v1/forecast?latitude={$coords['lat']}&longitude={$coords['lon']}"
           . "&current=temperature_2m,relative_humidity_2m,weathercode,windspeed_10m"
           . "&timezone=Asia%2FKuala_Lumpur";
$meteoData = fetchData($meteoUrl);
if ($meteoData && isset($meteoData['current'])) {
    $currentTemp     = $meteoData['current']['temperature_2m'] ?? null;
    $currentHumidity = $meteoData['current']['relative_humidity_2m'] ?? null;
    $currentWind     = $meteoData['current']['windspeed_10m'] ?? null;
    $wmoCodes = [
        0=>'Clear Sky', 1=>'Mainly Clear', 2=>'Partly Cloudy', 3=>'Overcast',
        45=>'Foggy', 48=>'Icy Fog',
        51=>'Light Drizzle', 53=>'Drizzle', 55=>'Heavy Drizzle',
        61=>'Light Rain', 63=>'Rain', 65=>'Heavy Rain',
        80=>'Rain Showers', 81=>'Heavy Showers', 82=>'Violent Showers',
        95=>'Thunderstorm', 96=>'Thunderstorm w/ Hail', 99=>'Heavy Thunderstorm',
    ];
    $wcode          = $meteoData['current']['weathercode'] ?? null;
    $currentWeather = $wmoCodes[$wcode] ?? 'Unknown';
    }
}

// Keywords from CSV: all Klang Valley location names + state names
$kvKeywords = array_map('strtolower', array_merge($klangValleyStates, $klangValleyLocNames));

// Filter warnings to only those mentioning Klang Valley region
$klangValleyWarnings = [];
if ($warningData) {
    foreach ($warningData as $warning) {
        if (empty($warning['valid_from']) || empty($warning['valid_to'])) continue;
        $searchable = strtolower(implode(' ', [
            $warning['warning_issue']['title_en'] ?? '',
            $warning['warning_issue']['title_bm'] ?? '',
            $warning['heading_en'] ?? '',
            $warning['heading_bm'] ?? '',
            $warning['text_en']    ?? '',
            $warning['text_bm']    ?? '',
        ]));
        foreach ($kvKeywords as $keyword) {
            if (strpos($searchable, $keyword) !== false) {
                $klangValleyWarnings[] = $warning;
                break;
            }
        }
    }
}

$currentTime = date('l, F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>National Weather Service Malaysia - Klang Valley</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .warning-header {
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
        }
        .warning-header:hover { opacity: 0.85; }
        .warning-toggle {
            font-size: 12px;
            flex-shrink: 0;
            margin-left: 10px;
            transition: transform 0.2s;
        }
        .warning-toggle.open { transform: rotate(180deg); }
        .warning-body { display: none; margin-top: 10px; }
        .warning-body.open { display: block; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <a href="/" style="text-decoration:none;color:white;display:inline-flex;align-items:center;gap:14px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 64 64" fill="none"><circle cx="32" cy="22" r="10" fill="#FFD54F"/><line x1="32" y1="4" x2="32" y2="10" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="32" y1="34" x2="32" y2="40" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="14" y1="22" x2="20" y2="22" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="44" y1="22" x2="50" y2="22" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="18" y1="8" x2="22" y2="12" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="42" y1="32" x2="46" y2="36" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="46" y1="8" x2="42" y2="12" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><line x1="18" y1="36" x2="22" y2="32" stroke="#FFD54F" stroke-width="3" stroke-linecap="round"/><rect x="8" y="36" width="48" height="18" rx="9" fill="white" opacity="0.95"/><circle cx="20" cy="36" r="9" fill="white" opacity="0.95"/><circle cx="36" cy="32" r="12" fill="white" opacity="0.95"/><circle cx="50" cy="37" r="7" fill="white" opacity="0.95"/></svg>
                <div>
                    <div style="font-size:22px;font-weight:bold;letter-spacing:1px;">Cuaca Lembah Klang</div>
                    <div style="font-size:12px;opacity:0.75;letter-spacing:2px;">PERKHIDMATAN RAMALAN CUACA</div>
                </div>
            </a>
        </div>
    </div>

    <div class="nav">
        <div class="container" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap;">
            <form method="get" action="" style="display:flex; align-items:center; gap:8px; margin:0;">
                <label for="loc-select" style="font-size:13px; font-weight:bold; color:#333;">KAWASAN:</label>
                <select id="loc-select" name="location" onchange="this.form.submit();" style="font-size:13px; padding:3px 6px; border:1px solid #aaa; background:#fff;">
                    <option value="" <?php echo ($selectedLocation === '') ? 'selected' : ''; ?>>— Semua Kawasan —</option>
                    <?php foreach ($allLocationNames as $name): ?>
                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo ($name === $selectedLocation) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="#warnings" style="color:#3F51B5; text-decoration:none; font-size:13px;">CURRENT HAZARDS</a>
            <a href="#forecast" style="color:#3F51B5; text-decoration:none; font-size:13px;">FORECAST</a>
        </div>
    </div>

    <div class="container">
        <div class="content">
            <div class="layout">
                <div class="main-content">
                    <!-- RAMALAN CUACA SIGNIFIKAN -->
                    <?php if (!empty($advisoryText)): ?>
					<div class="advisory-topbar">
					<div class="advisory-topbar-icon">
						<img src="https://www.weather.gov/bundles/templating/images/top_news/important.png" alt="!">
					</div>
					<div class="advisory-topbar-content">
						<div class="advisory-topbar-title">Ramalan Cuaca Signifikan</div>
						<div class="advisory-topbar-body">
						<?php echo htmlspecialchars($advisoryText); ?>
						<?php if ($advisoryIssued): ?>
							<span class="advisory-topbar-issued"><?php echo htmlspecialchars($advisoryIssued); ?></span>
						<?php endif; ?>
						<a class="advisory-topbar-link" href="https://www.met.gov.my/data/pocgn/ramalancuacasignifikan.jpg" target="_blank">Baca Lanjut &rsaquo;</a>
						</div>
					</div>
					</div>
					<?php endif; ?>

					<!-- CURRENT CONDITIONS STRIP -->
					<?php if ($currentTemp !== null): ?>

					<?php
					// Force default location if value is missing, null, empty, or "null"
					$displayLocation = $selectedLocation;

					if (!isset($displayLocation) || $displayLocation === null || trim($displayLocation) === '' || strtolower($displayLocation) === 'null') {
						$displayLocation = 'Kuala Lumpur';
					}
					?>

					<div style="background:#1A237E; color:#fff; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:30px; flex-wrap:wrap;">
						
						<div>
							<div style="font-size:11px; text-transform:uppercase; opacity:0.7;">Current Conditions</div>
							<div style="font-size:13px; font-weight:bold;">
								<?php echo htmlspecialchars($displayLocation); ?>
							</div>
						</div>

						<div style="font-size:36px; font-weight:bold; line-height:1;">
							<?php echo round($currentTemp); ?>°C
						</div>

						<div style="font-size:13px; line-height:1.8;">
							<strong><?php echo htmlspecialchars($currentWeather ?? ''); ?></strong><br>
							Humidity: <?php echo htmlspecialchars($currentHumidity ?? ''); ?>%
							&nbsp;|&nbsp;
							Wind: <?php echo htmlspecialchars($currentWind ?? ''); ?> km/h
						</div>

						<div style="margin-left:auto; font-size:11px; opacity:0.6;">
							Source: Open-Meteo
						</div>

					</div>

					<?php endif; ?>

                    <!-- WARNINGS SECTION -->
                    <div id="warnings" class="warning-section">
                        <div class="section-title">⚠ WATCHES, WARNINGS &amp; ADVISORIES — <?php echo htmlspecialchars(strtoupper($selectedLocation)); ?></div>
                        <?php if ($warningData === null): ?>
                            <div class="error">ERROR: Unable to load warning data from MET Malaysia API</div>
                        <?php elseif (empty($filteredWarnings)): ?>
                            <div class="no-warnings">✓ NO ACTIVE WARNINGS OR ADVISORIES AFFECTING <?php echo htmlspecialchars(strtoupper($selectedLocation)); ?> AT THIS TIME</div>
                        <?php else: ?>
                            <div class="update-time">
                                <?php echo count($filteredWarnings); ?> active warning(s) — click to expand
                            </div>
                            <?php foreach ($filteredWarnings as $i => $warning): ?>
                                <?php
                                $issued    = isset($warning['warning_issue']['issued']) ? date('D, M j, Y g:i A', strtotime($warning['warning_issue']['issued'])) : 'N/A';
                                $validFrom = isset($warning['valid_from'])              ? date('D, M j, Y g:i A', strtotime($warning['valid_from']))              : 'N/A';
                                $validTo   = isset($warning['valid_to'])                ? date('D, M j, Y g:i A', strtotime($warning['valid_to']))                : 'N/A';
                                $title     = htmlspecialchars($warning['warning_issue']['title_en'] ?: ($warning['warning_issue']['title_bm'] ?? 'Warning'));
                                $bodyId    = 'warning-body-' . $i;
                                $iconId    = 'warning-icon-' . $i;
                                ?>
                                <div class="warning-box">
                                    <div class="warning-header" onclick="toggleWarning('<?php echo $bodyId; ?>', '<?php echo $iconId; ?>')">
                                        <span><?php echo $title; ?></span>
                                        <span class="warning-toggle" id="<?php echo $iconId; ?>">▼</span>
                                    </div>
                                    <div class="warning-body" id="<?php echo $bodyId; ?>">
                                        <div class="warning-meta">
                                            <strong>ISSUED:</strong> <?php echo $issued; ?><br>
                                            <strong>VALID FROM:</strong> <?php echo $validFrom; ?><br>
                                            <strong>VALID TO:</strong> <?php echo $validTo; ?>
                                        </div>
                                        <?php if (!empty($warning['heading_en'])): ?>
                                            <div style="font-weight:bold; margin:10px 0;">
                                                <?php echo htmlspecialchars($warning['heading_en']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="warning-text">
                                            <?php echo nl2br(htmlspecialchars($warning['text_bm'] ?: 'N/A')); ?>
                                        </div>
                                        <?php if (!empty($warning['instruction_en'])): ?>
                                            <div class="warning-text">
                                                <strong>INSTRUCTIONS:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($warning['instruction_en'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- RADAR MALAYSIA -->
                    <div class="section" style="margin-bottom:20px;">
                        <div class="section-title">🛰 RADAR MALAYSIA</div>
                        <div style="border:1px solid #999;background:#000;overflow:hidden;">
                            <img
                                src="https://www.met.gov.my/data/radar_malaysia.gif?<?php echo time(); ?>"
                                alt="Malaysia Weather Radar"
                                style="display:block;width:100%;height:auto;"
                            >
                            <div style="background:#E8E8E8;border-top:1px solid #999;padding:5px 10px;font-size:11px;color:#444;">
                                Komposit Radar Cuaca Malaysia &mdash;
                                <a href="https://www.met.gov.my" target="_blank">MET Malaysia</a>
                                &bull; <span >Dikemaskini setiap ~10 minit</span>
                            </div>
                        </div>
                    </div>

                    <!-- FORECAST SECTION -->
                    <div id="forecast" class="section">
                        <div class="section-title">7-DAY FORECAST — <?php echo htmlspecialchars(strtoupper($selectedLocation)); ?></div>
                        <?php if ($forecastData === null): ?>
                            <div class="error">ERROR: Unable to load forecast data from MET Malaysia API</div>
                        <?php elseif (empty($filteredForecasts)): ?>
                            <div class="error">NO FORECAST DATA AVAILABLE FOR <?php echo htmlspecialchars(strtoupper($selectedLocation)); ?></div>
                        <?php else: ?>
                            <div class="update-time">Forecast issued: Daily by MET Malaysia</div>
                            <?php foreach ($filteredForecasts as $location => $forecasts): ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>DATE</th>
                                            <th>MORNING</th>
                                            <th>AFTERNOON</th>
                                            <th>NIGHT</th>
                                            <th>SUMMARY</th>
                                            <th>TEMP (°C)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $seenDates = [];
                                        foreach ($forecasts as $forecast):
                                            if (isset($seenDates[$forecast['date']])) continue;
                                            $seenDates[$forecast['date']] = true;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo date('D, M j', strtotime($forecast['date'])); ?></strong></td>
                                            <td><?php echo weatherEmoji($forecast['morning_forecast'])   . ' ' . htmlspecialchars($forecast['morning_forecast']); ?></td>
                                            <td><?php echo weatherEmoji($forecast['afternoon_forecast']) . ' ' . htmlspecialchars($forecast['afternoon_forecast']); ?></td>
                                            <td><?php echo weatherEmoji($forecast['night_forecast'])     . ' ' . htmlspecialchars($forecast['night_forecast']); ?></td>
                                            <td>
                                                <strong><?php echo weatherEmoji($forecast['summary_forecast']) . ' ' . htmlspecialchars($forecast['summary_forecast']); ?></strong><br>
                                                <small>(<?php echo htmlspecialchars($forecast['summary_when']); ?>)</small>
                                            </td>
                                            <td class="temp-data">
                                                High: <span class="temp-high"><?php echo $forecast['max_temp']; ?>°</span> /
                                                Low: <span class="temp-low"><?php echo $forecast['min_temp']; ?>°</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php /* EARTHQUAKE SECTION — commented out
                    <div id="earthquake" class="section">
                        <div class="section-title">RECENT EARTHQUAKE ACTIVITY</div>
                        <?php if ($earthquakeData === null): ?>
                            <div class="error">ERROR: Unable to load earthquake data from MET Malaysia API</div>
                        <?php elseif (empty($earthquakeData)): ?>
                            <div style="padding: 15px;">No recent earthquake activity reported</div>
                        <?php else: ?>
                            <div class="update-time">Recent earthquake events (Updated when required)</div>
                            <table class="earthquake-table">
                                <thead>
                                    <tr>
                                        <th>DATE/TIME (LOCAL)</th>
                                        <th>MAGNITUDE</th>
                                        <th>DEPTH</th>
                                        <th>LOCATION</th>
                                        <th>DISTANCE FROM MALAYSIA</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    foreach ($earthquakeData as $eq):
                                        $mag       = $eq['magdefault'] ?? 0;
                                        $magClass  = $mag >= 6.0 ? 'mag-high' : ($mag >= 5.0 ? 'mag-medium' : '');
                                        $localTime = isset($eq['localdatetime']) ? date('l, M j, Y g:i A', strtotime($eq['localdatetime'])) : 'N/A';
                                        <tr>
                                            <td>$localTime</td>
                                            <td class="magnitude $magClass">$mag magtypedefault</td>
                                            <td>depth km</td>
                                            <td><strong>location</strong><br>location_original<br>lat, lon</td>
                                            <td><strong>Malaysia:</strong> nbm_distancemas<br><strong>Other:</strong> nbm_distancerest</td>
                                        </tr>
                                    endforeach
                                </tbody>
                            </table>
                        endif
                    </div>
                    */ ?>

                </div>

                <div class="sidebar">

                    <div class="info-box">
                        <h3>DATA SOURCE</h3>
                        <p>Malaysian Meteorological Department (MET Malaysia)</p>
                        <p style="margin-top: 8px; font-size: 12px;">
                            <strong>Page loaded:</strong><br><?php echo $currentTime; ?>
                        </p>
                    </div>

                    <div class="info-box">
                        <h3>API STATUS</h3>
                        <p>
                            <strong>Forecast:</strong>
                            <span class="<?php echo $forecastData   ? 'status-online' : 'status-offline'; ?>">
                                <?php echo $forecastData   ? '✓ Online' : '✗ Error'; ?>
                            </span><br>
                            <strong>Warnings:</strong>
                            <span class="<?php echo $warningData    ? 'status-online' : 'status-offline'; ?>">
                                <?php echo $warningData    ? '✓ Online' : '✗ Error'; ?>
                            </span><br>
                            <strong>Earthquake:</strong>
                            <span class="<?php echo $earthquakeData ? 'status-online' : 'status-offline'; ?>">
                                <?php echo $earthquakeData ? '✓ Online' : '✗ Error'; ?>
                            </span>
                        </p>
                    </div>

                    <div class="info-box">
                        <h3>QUICK LINKS</h3>
                        <ul style="list-style: none; margin-left: 0;">
                            <li><a href="https://www.met.gov.my/data/pocgn/ramalancuacakhas_bm.pdf" target="_blank">Ramalan Cuaca Khas</a></li>
                            <li><a href="<?php echo $forecastApiUrl; ?>" target="_blank">Raw Forecast Data</a></li>
                            <li><a href="<?php echo $warningApiUrl; ?>" target="_blank">Raw Warning Data</a></li>
                            <li><a href="<?php echo $earthquakeApiUrl; ?>" target="_blank">Raw Earthquake Data</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleWarning(bodyId, iconId) {
            var body = document.getElementById(bodyId);
            var icon = document.getElementById(iconId);
            if (body.classList.contains('open')) {
                body.classList.remove('open');
                icon.classList.remove('open');
            } else {
                body.classList.add('open');
                icon.classList.add('open');
            }
        }

        function jumpTo(zone, anchorId) {
            // Highlight clicked zone
            document.querySelectorAll('.map-zone').forEach(function(z) {
                z.classList.remove('map-zone-active');
            });
            zone.classList.add('map-zone-active');

            // Scroll to the location header
            var target = document.getElementById(anchorId);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                // Flash highlight
                target.classList.add('location-header-flash');
                setTimeout(function() {
                    target.classList.remove('location-header-flash');
                }, 2000);
            }
        }
    </script>
</body>
</html>
