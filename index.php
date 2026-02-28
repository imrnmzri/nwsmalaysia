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
function fetchData($url) {
    $context = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'NWS-Malaysia/1.0']
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    return json_decode($response, true);
}

// OCR the advisory image with Tesseract
function getAdvisoryOcrText() {
    $imageUrl  = 'https://www.met.gov.my/data/pocgn/ramalancuacasignifikan.jpg';
    $tmpImage  = sys_get_temp_dir() . '/advisory_' . md5($imageUrl) . '.jpg';
    $tmpOutput = sys_get_temp_dir() . '/advisory_ocr_' . md5($imageUrl);
    $cacheFile = $tmpOutput . '.txt';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
        return file_get_contents($cacheFile);
    }

    $imageData = @file_get_contents($imageUrl);
    if ($imageData === false) return null;
    file_put_contents($tmpImage, $imageData);

    $tesseractPath = '/usr/bin/tesseract';
    exec(escapeshellcmd("$tesseractPath " . escapeshellarg($tmpImage) . ' ' . escapeshellarg($tmpOutput) . ' -l msa 2>/dev/null'));

    if (!file_exists($cacheFile)) return null;
    return file_get_contents($cacheFile);
}

// Extract body text and issued date from raw OCR
function parseAdvisoryOcr($rawOcr) {
    $body   = '';
    $issued = '';

    if (preg_match('/RAMALAN.+?(?=Dikeluarkan)/is', $rawOcr, $m)) {
        $body = trim(preg_replace('/^.*?SIGNIFIKAN\s*/is', '', trim($m[0])));
        $body = trim(preg_replace('/^[@\s]+/', '', $body));
        $body = trim(preg_replace('/Orang awam.+$/is', '', $body));
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

// Build location-to-anchor map for the SVG click targets
// Keys must loosely match location_name from API
$locationAnchors = [];
foreach ($forecastsByLocation as $location => $forecasts) {
    $id = 'loc-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($location));
    $locationAnchors[$location] = $id;
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
            <h1>KLANG VALLEY WEATHER FORECAST</h1>
        </div>
    </div>

    <div class="nav">
        <div class="container">
            <a href="#warnings">CURRENT HAZARDS</a>
            <a href="#forecast">FORECAST</a>
            <a href="#earthquake">EARTHQUAKE DATA</a>
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

                    <!-- WARNINGS SECTION -->
                    <div id="warnings" class="warning-section">
                        <div class="section-title">⚠ WATCHES, WARNINGS &amp; ADVISORIES — KLANG VALLEY</div>
                        <?php if ($warningData === null): ?>
                            <div class="error">ERROR: Unable to load warning data from MET Malaysia API</div>
                        <?php elseif (empty($klangValleyWarnings)): ?>
                            <div class="no-warnings">✓ NO ACTIVE WARNINGS OR ADVISORIES AFFECTING KLANG VALLEY AT THIS TIME</div>
                        <?php else: ?>
                            <div class="update-time">
                                <?php echo count($klangValleyWarnings); ?> active warning(s) affecting Klang Valley — click to expand
                            </div>
                            <?php foreach ($klangValleyWarnings as $i => $warning): ?>
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

                    <!-- FORECAST SECTION -->
                    <div id="forecast" class="section">
                        <div class="section-title">7-DAY FORECAST - KLANG VALLEY</div>
                        <?php if ($forecastData === null): ?>
                            <div class="error">ERROR: Unable to load forecast data from MET Malaysia API</div>
                        <?php elseif (empty($forecastsByLocation)): ?>
                            <div class="error">NO FORECAST DATA AVAILABLE FOR KLANG VALLEY</div>
                        <?php else: ?>
                            <div class="update-time">Forecast issued: Daily by MET Malaysia</div>
                            <?php foreach ($forecastsByLocation as $location => $forecasts): ?>
                                <?php $anchorId = 'loc-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($location)); ?>
                                <div class="location-header" id="<?php echo $anchorId; ?>">
                                    <?php echo htmlspecialchars($location); ?>
                                </div>
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
