<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// API endpoints
$forecastApiUrl   = "https://api.data.gov.my/weather/forecast/";
$warningApiUrl    = "https://api.data.gov.my/weather/warning/";
$earthquakeApiUrl = "https://api.data.gov.my/weather/warning/earthquake";

// Klang Valley town (bandar) locations to filter
$klangValleyTowns = ['Kuala Lumpur', 'Petaling', 'Shah Alam', 'Klang', 'Putrajaya', 'Subang', 'Damansara'];

// Fetch data function
function fetchData($url) {
    $context = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'NWS-Malaysia/1.0']
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    return json_decode($response, true);
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

// Keywords indicating a warning affects Klang Valley
$kvKeywords = [
    'klang valley', 'lembah klang',
    'kuala lumpur', 'wilayah persekutuan',
    'selangor', 'petaling', 'shah alam',
    'subang', 'klang', 'putrajaya',
    'cyberjaya', 'ampang', 'cheras',
    'kepong', 'bangsar', 'damansara'
];

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

                    <!-- SIGNIFICANT WEATHER ADVISORY IMAGE -->
                    <div class="advisory-image-section">
                        <div class="section-title">🌦️ SIGNIFICANT WEATHER ADVISORY</div>
                        <div class="advisory-image-box">
                            <img src="https://www.met.gov.my/data/pocgn/ramalancuacasignifikan.jpg" alt="Ramalan Cuaca Signifikan">
                        </div>
                    </div>

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
                                <div class="location-header"><?php echo htmlspecialchars($location); ?></div>
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

                    <!-- EARTHQUAKE SECTION -->
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
                                    <?php foreach ($earthquakeData as $eq): ?>
                                        <?php
                                        $mag       = $eq['magdefault'] ?? 0;
                                        $magClass  = $mag >= 6.0 ? 'mag-high' : ($mag >= 5.0 ? 'mag-medium' : '');
                                        $localTime = isset($eq['localdatetime']) ? date('l, M j, Y g:i A', strtotime($eq['localdatetime'])) : 'N/A';
                                        ?>
                                        <tr>
                                            <td><?php echo $localTime; ?></td>
                                            <td class="magnitude <?php echo $magClass; ?>">
                                                <?php echo $mag; ?> <?php echo htmlspecialchars($eq['magtypedefault'] ?? 'N/A'); ?>
                                            </td>
                                            <td><?php echo $eq['depth'] ?? 'N/A'; ?> km</td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($eq['location'] ?? 'N/A'); ?></strong><br>
                                                <small><?php echo htmlspecialchars($eq['location_original'] ?? 'N/A'); ?></small><br>
                                                <small><?php echo htmlspecialchars($eq['lat_vector'] ?? ''); ?>, <?php echo htmlspecialchars($eq['lon_vector'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <strong>Malaysia:</strong> <?php echo htmlspecialchars($eq['nbm_distancemas'] ?? 'N/A'); ?><br>
                                                <strong>Other:</strong> <?php echo htmlspecialchars($eq['nbm_distancerest'] ?? 'N/A'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                </div>

                <div class="sidebar">
                    <div class="info-box">
                        <h3>KLANG VALLEY COVERAGE</h3>
                        <ul>
                            <li>Kuala Lumpur</li>
                            <li>Petaling Jaya</li>
                            <li>Shah Alam</li>
                            <li>Subang Jaya</li>
                            <li>Pelabuhan Klang</li>
                            <li>Putrajaya</li>
                            <li>Cyberjaya</li>
                            <li>Damansara</li>
                        </ul>
                    </div>

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
    </script>
</body>
</html>
