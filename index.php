<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// API endpoints
$forecastApiUrl   = "https://api.data.gov.my/weather/forecast/";
$warningApiUrl    = "https://api.data.gov.my/weather/warning/";

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
$currentFeelsLike = $currentDewpoint = $currentPrecip = $currentCloud = $currentPressure = $currentVisibility = $currentWindDir = null;
$coords = null;
foreach ($townCoords as $town => $c) {
    if (stripos($selectedLocation, $town) !== false || stripos($town, $selectedLocation) !== false) {
        $coords = $c; break;
    }
}
if ($coords) {
    $meteoUrl  = "https://api.open-meteo.com/v1/forecast?latitude={$coords['lat']}&longitude={$coords['lon']}"
               . "&current=temperature_2m,relative_humidity_2m,weathercode,windspeed_10m,winddirection_10m,apparent_temperature,dewpoint_2m,precipitation,cloudcover,surface_pressure,visibility"
               . "&timezone=Asia%2FKuala_Lumpur";
    $meteoData = fetchData($meteoUrl);
    if ($meteoData && isset($meteoData['current'])) {
        $currentTemp        = $meteoData['current']['temperature_2m'] ?? null;
        $currentHumidity    = $meteoData['current']['relative_humidity_2m'] ?? null;
        $currentWind        = $meteoData['current']['windspeed_10m'] ?? null;
        $currentWindDir     = $meteoData['current']['winddirection_10m'] ?? null;
        $currentFeelsLike   = $meteoData['current']['apparent_temperature'] ?? null;
        $currentDewpoint    = $meteoData['current']['dewpoint_2m'] ?? null;
        $currentPrecip      = $meteoData['current']['precipitation'] ?? null;
        $currentCloud       = $meteoData['current']['cloudcover'] ?? null;
        $currentPressure    = $meteoData['current']['surface_pressure'] ?? null;
        $currentVisibility  = $meteoData['current']['visibility'] ?? null;
        $wmoCodes = [
            0=>'Langit Cerah', 1=>'Kebanyakan Cerah', 2=>'Sebahagian Berawan', 3=>'Mendung',
            45=>'Berkabus', 48=>'Kabus Beku',
            51=>'Hujan Renyai Ringan', 53=>'Hujan Renyai', 55=>'Hujan Renyai Lebat',
            61=>'Hujan Ringan', 63=>'Hujan', 65=>'Hujan Lebat',
            80=>'Hujan Lebat Seketika', 81=>'Hujan Lebat', 82=>'Hujan Lebat Sangat',
            95=>'Ribut Petir', 96=>'Ribut Petir dengan Hujan Batu', 99=>'Ribut Petir Kuat',
        ];
        $wcode          = $meteoData['current']['weathercode'] ?? null;
        $currentWeather = $wmoCodes[$wcode] ?? 'Tidak Diketahui';
    }
}
function windDirCompass($deg) {
    if ($deg === null) return '';
    $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $dirs[round($deg / 22.5) % 16];
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
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuaca Lembah Klang — Jabatan Meteorologi Malaysia (Prototaip)</title>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>">
</head>
<body>
    <div class="header">
        <div class="container">
            <a href="/" class="header-link">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 64 64" fill="none"><circle cx="32" cy="22" r="10" fill="#FFD54F"/><line x1="32" y1="4" x2="32" y2="10" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="32" y1="34" x2="32" y2="40" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="14" y1="22" x2="20" y2="22" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="44" y1="22" x2="50" y2="22" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="18" y1="8" x2="22" y2="12" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="42" y1="32" x2="46" y2="36" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="46" y1="8" x2="42" y2="12" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><line x1="18" y1="36" x2="22" y2="32" stroke="#a9c25d" stroke-width="3" stroke-linecap="round"/><rect x="8" y="36" width="48" height="18" rx="9" fill="white" opacity="0.95"/><circle cx="20" cy="36" r="9" fill="white" opacity="0.95"/><circle cx="36" cy="32" r="12" fill="white" opacity="0.95"/><circle cx="50" cy="37" r="7" fill="white" opacity="0.95"/></svg>
                <div>
                    <div class="header-title">JABATAN METEOROLOGI MALAYSIA</div>
                    <div class="header-subtitle">Cuaca Lembah Klang</div>
                </div>
            </a>
        </div>
    </div>

    <!-- NAV -->
    <div class="nav">
		<div class="container">
			<div class="nav-inner">
				<form method="get" action="" class="nav-form">
					<label for="loc-select" class="nav-label">KAWASAN:</label>
					<select id="loc-select" name="location" onchange="this.form.submit();" class="nav-select">
						<option value="" <?php echo ($selectedLocation === '') ? 'selected' : ''; ?>>— Semua Kawasan —</option>
						<?php foreach ($allLocationNames as $name): ?>
						<option value="<?php echo htmlspecialchars($name); ?>" <?php echo ($name === $selectedLocation) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($name); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</form>
				<a href="#current-conditions">KEADAAN SEMASA</a>
				<a href="#warnings">AMARAN</a>
				<a href="#forecast">RAMALAN</a>
				<a href="https://www.met.gov.my/pencerapan/nowcasting/" target="_blank">NOWCASTING</a>
				<span class="nav-item">IKLIM</span>
				<span class="nav-item">PENERBITAN</span>
				<span class="nav-item">PENDIDIKAN</span>
				<span class="nav-item">TENTANG KAMI</span>
			</div>
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

					<!-- KEADAAN SEMASA -->
					<?php if ($currentTemp !== null): ?>

					<?php
					$displayLocation = $selectedLocation;
					if (!isset($displayLocation) || $displayLocation === null || trim($displayLocation) === '' || strtolower($displayLocation) === 'null') {
						$displayLocation = 'Kuala Lumpur';
					}
					?>

					<div id="current-conditions" class="current-conditions">

						<!-- Kiri: suhu besar + keadaan -->
						<div class="conditions-left">
							<div class="conditions-label">Keadaan Semasa</div>
							<div class="conditions-location"><?php echo htmlspecialchars($displayLocation); ?></div>
							<div class="conditions-temp"><?php echo round($currentTemp); ?>°C</div>
							<div class="conditions-weather"><?php echo htmlspecialchars($currentWeather ?? ''); ?></div>
						</div>

						<!-- Kanan: jadual butiran -->
						<div class="conditions-right">
							<table class="conditions-table">
								<tr>
									<td class="lbl">Kelembapan</td>
									<td><?php echo $currentHumidity ?? 'T/A'; ?>%</td>
									<td class="lbl2">Titik Embun</td>
									<td><?php echo $currentDewpoint !== null ? round($currentDewpoint).'°C' : 'T/A'; ?></td>
								</tr>
								<tr>
									<td class="lbl">Angin</td>
									<td><?php echo $currentWind !== null ? $currentWind.' km/j '.windDirCompass($currentWindDir) : 'T/A'; ?></td>
									<td class="lbl2">Rasa Seperti</td>
									<td><?php echo $currentFeelsLike !== null ? round($currentFeelsLike).'°C' : 'T/A'; ?></td>
								</tr>
								<tr>
									<td class="lbl">Tekanan</td>
									<td><?php echo $currentPressure !== null ? round($currentPressure).' hPa' : 'T/A'; ?></td>
									<td class="lbl2">Jarak Pandang</td>
									<td><?php echo $currentVisibility !== null ? round($currentVisibility/1000,1).' km' : 'T/A'; ?></td>
								</tr>
								<tr>
									<td class="lbl">Hujan</td>
									<td><?php echo $currentPrecip !== null ? $currentPrecip.' mm' : 'T/A'; ?></td>
									<td class="lbl2">Liputan Awan</td>
									<td><?php echo $currentCloud !== null ? $currentCloud.'%' : 'T/A'; ?></td>
								</tr>
								<tr>
									<td colspan="4" class="conditions-source">Sumber: Open-Meteo</td>
								</tr>
							</table>
						</div>

					</div>

					<?php endif; ?>

                    <!-- BAHAGIAN AMARAN -->
                    <div id="warnings" class="warning-section">
                        <div class="section-title">AMARAN, WASPADA &amp; NASIHAT<?php echo $selectedLocation ? ' &mdash; ' . htmlspecialchars(strtoupper($selectedLocation)) : ''; ?></div>
                        <?php if ($warningData === null): ?>
                            <div class="error">RALAT: Tidak dapat memuatkan data amaran daripada API MetMalaysia</div>
                        <?php elseif (empty($filteredWarnings)): ?>
                            <div class="no-warnings">&#10003; TIADA AMARAN AKTIF<?php echo $selectedLocation ? ' MEMPENGARUHI ' . htmlspecialchars(strtoupper($selectedLocation)) : ' UNTUK LEMBAH KLANG'; ?> PADA MASA INI</div>
                        <?php else: ?>
                            <?php foreach ($filteredWarnings as $i => $warning): ?>
                                <?php
                                $issued    = isset($warning['warning_issue']['issued']) ? date('D, M j, Y g:i A', strtotime($warning['warning_issue']['issued'])) : 'T/A';
                                $validFrom = isset($warning['valid_from'])              ? date('D, M j, Y g:i A', strtotime($warning['valid_from']))              : 'T/A';
                                $validTo   = isset($warning['valid_to'])                ? date('D, M j, Y g:i A', strtotime($warning['valid_to']))                : 'T/A';
                                $title     = htmlspecialchars($warning['warning_issue']['title_bm'] ?: ($warning['warning_issue']['title_bm'] ?? 'Amaran'));
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
                                            <strong>DIKELUARKAN:</strong> <?php echo $issued; ?><br>
                                            <strong>SAH DARI:</strong> <?php echo $validFrom; ?><br>
                                            <strong>SAH SEHINGGA:</strong> <?php echo $validTo; ?>
                                        </div>
                                        <?php if (!empty($warning['heading_bm'])): ?>
                                            <div class="warning-heading">
                                                <?php echo htmlspecialchars($warning['heading_bm']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="warning-text">
                                            <?php echo nl2br(htmlspecialchars($warning['text_bm'] ?: 'T/A')); ?>
                                        </div>
                                        <?php if (!empty($warning['instruction_en'])): ?>
                                            <div class="warning-text">
                                                <strong>ARAHAN:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($warning['instruction_en'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- RADAR MALAYSIA -->
                    <div id="radar" class="section">
                        <div class="section-title">RADAR MALAYSIA</div>
                        <div class="radar-wrap">
                            <img src="https://www.met.gov.my/data/radar_malaysia.gif?<?php echo time(); ?>"
                                 alt="Radar Cuaca Malaysia"
                                 class="radar-img">
                            <div class="radar-caption">
                                Komposit Radar Cuaca Malaysia &mdash;
                                <a href="https://www.met.gov.my" target="_blank">MetMalaysia</a>
                                &bull; Dikemaskini setiap ~10 minit
                            </div>
                        </div>
                    </div>

                    <!-- BAHAGIAN RAMALAN -->
                    <div id="forecast" class="section">
                        <div class="section-title">RAMALAN 7 HARI<?php echo $selectedLocation ? ' &mdash; ' . htmlspecialchars(strtoupper($selectedLocation)) : ''; ?></div>
                        <?php if ($forecastData === null): ?>
                            <div class="error">RALAT: Tidak dapat memuatkan data ramalan daripada API MetMalaysia</div>
                        <?php elseif (empty($filteredForecasts)): ?>
                            <div class="error">TIADA DATA RAMALAN UNTUK <?php echo htmlspecialchars(strtoupper($selectedLocation)); ?></div>
                        <?php else: ?>
                            <?php foreach ($filteredForecasts as $location => $forecasts): ?>
                                <div class="location-header" id="<?php echo $locationAnchors[$location] ?? ''; ?>">
                                    <?php echo htmlspecialchars($location); ?>
                                </div>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>TARIKH</th>
                                            <th>PAGI</th>
                                            <th>PETANG</th>
                                            <th>MALAM</th>
                                            <th>RINGKASAN</th>
                                            <th>SUHU (°C)</th>
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
                                            <td><?php $e = weatherEmoji($forecast['morning_forecast']); echo ($e ? $e.' ' : '') . htmlspecialchars($forecast['morning_forecast']); ?></td>
                                            <td><?php $e = weatherEmoji($forecast['afternoon_forecast']); echo ($e ? $e.' ' : '') . htmlspecialchars($forecast['afternoon_forecast']); ?></td>
                                            <td><?php $e = weatherEmoji($forecast['night_forecast']); echo ($e ? $e.' ' : '') . htmlspecialchars($forecast['night_forecast']); ?></td>
                                            <td>
                                                <?php $e = weatherEmoji($forecast['summary_forecast']); ?>
                                                <strong><?php echo ($e ? $e.' ' : '') . htmlspecialchars($forecast['summary_forecast']); ?></strong><br>
                                                <small>(<?php echo htmlspecialchars($forecast['summary_when']); ?>)</small>
                                            </td>
                                            <td class="temp-data">
                                                Maks: <span class="temp-high"><?php echo $forecast['max_temp']; ?>°</span> /
                                                Min: <span class="temp-low"><?php echo $forecast['min_temp']; ?>°</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>



                </div>

                <div class="sidebar">

                    <!-- PETA AMARAN MET -->
                    <?php
                    $warningMaps = [
                        ['url' => 'https://www.met.gov.my/data/AmaranHujanLebat.jpg',      'label' => 'Amaran Hujan Lebat'],
                        ['url' => 'https://www.met.gov.my/data/AmaranRibutPetir.jpg',      'label' => 'Amaran Ribut Petir'],
                        ['url' => 'https://www.met.gov.my/data/AmaranAnginKencangLaut.jpg', 'label' => 'Amaran Angin Kencang Laut'],
                    ];
                    foreach ($warningMaps as $map):
                    ?>
                    <div class="warning-map-box">
                        <div class="warning-map-title"><?php echo htmlspecialchars($map['label']); ?></div>
                        <a href="<?php echo $map['url']; ?>" target="_blank">
                            <img src="<?php echo $map['url']; ?>?<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($map['label']); ?>"
                                 loading="lazy">
                        </a>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-wrap">
            <!-- Col 1: brand + social -->
            <div>
                <strong>Kementerian Sumber Asli dan Kelestarian Alam</strong><br>
				Jabatan Meteorologi Malaysia<br>
				Jalan Sultan<br>
				46667, Petaling Jaya, Selangor<br>
                Hotline: 1-300-22-1638<br>
                <div class="footer-social">
                    <span class="social-icon" title="Facebook"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></span>
                    <span class="social-icon" title="Twitter/X"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></span>
                    <span class="social-icon" title="Instagram"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></span>
                    <span class="social-icon" title="YouTube"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 0 0 1.46 6.42 29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.96-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="white"/></svg></span>
                    <span class="social-icon" title="TikTok"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.75a4.85 4.85 0 0 1-1.01-.06z"/></svg></span>
                </div>
            </div>
            <!-- Col 2: quick links -->
            <div>
                <strong>Pautan Pantas</strong><br>
                <a href="https://www.met.gov.my/pencerapan/nowcasting/" target="_blank">Nowcasting</a><br>
                <a href="https://www.met.gov.my/iklim/status-cuaca-panas/" target="_blank">Status Cuaca Panas</a><br>
                <a href="https://www.met.gov.my/data/pocgn/ramalancuacakhas_bm.pdf" target="_blank">Ramalan Cuaca Khas</a><br>
                <a href="<?php echo $forecastApiUrl; ?>" target="_blank">Data Ramalan</a><br>
                <a href="<?php echo $warningApiUrl; ?>" target="_blank">Data Amaran</a>
            </div>
            <!-- Col 3: API status -->
            <div>
                <strong>Status API</strong><br>
                Ramalan: <span class="<?php echo $forecastData ? 'status-online' : 'status-offline'; ?>"><?php echo $forecastData ? '✓ Dalam Talian' : '✗ Ralat'; ?></span><br>
                Amaran: <span class="<?php echo $warningData ? 'status-online' : 'status-offline'; ?>"><?php echo $warningData ? '✓ Dalam Talian' : '✗ Ralat'; ?></span><br>
                <span style="color:#999;font-size:11px"><?php echo $currentTime; ?></span>
            </div>
            <!-- Col 4: Policies -->
            <div>
                <strong>Dasar</strong><br>
				Dasar Penafian<br>
				Dasar Keselamatan<br>
				Dasar Privasi<br>
                Kenyataan Hak Cipta<br>
            </div>
        </div>
    </footer>


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
            document.querySelectorAll('.map-zone').forEach(function(z) {
                z.classList.remove('map-zone-active');
            });
            zone.classList.add('map-zone-active');

            var target = document.getElementById(anchorId);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                target.classList.add('location-header-flash');
                setTimeout(function() {
                    target.classList.remove('location-header-flash');
                }, 2000);
            }
        }
    </script>
</body>
</html>
