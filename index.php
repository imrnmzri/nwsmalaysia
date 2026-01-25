<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// API endpoints
$forecastApiUrl = "https://api.data.gov.my/weather/forecast/";
$warningApiUrl = "https://api.data.gov.my/weather/warning/";
$earthquakeApiUrl = "https://api.data.gov.my/weather/warning/earthquake";

// Klang Valley town (bandar) locations to filter
$klangValleyTowns = ['Kuala Lumpur', 'Petaling', 'Shah Alam', 'Klang', 'Putrajaya', 'Subang'];

// Fetch data function
function fetchData($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'NWS-Malaysia/1.0'
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}

// Fetch all data
$forecastData = fetchData($forecastApiUrl);
$warningData = fetchData($warningApiUrl);
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
            $locId = $forecast['location']['location_id'];
            
            // Only get Tn (Town/Bandar) locations
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
    if (!isset($forecastsByLocation[$location])) {
        $forecastsByLocation[$location] = [];
    }
    $forecastsByLocation[$location][] = $forecast;
}

// Sort each location's forecasts by date
foreach ($forecastsByLocation as $location => &$forecasts) {
    usort($forecasts, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}

// Get all warnings (no filtering by location)
$klangValleyWarnings = [];
if ($warningData) {
    foreach ($warningData as $warning) {
        // Only include warnings with valid dates
        if (!empty($warning['valid_from']) && !empty($warning['valid_to'])) {
            $klangValleyWarnings[] = $warning;
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            background: #fff;
            color: #000;
        }
        
        .header {
            background: #3F51B5;
            color: white;
            padding: 10px 20px;
            border-bottom: 3px solid #303F9F;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: normal;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 16px;
            font-weight: bold;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0;
        }
        
        .nav {
            background: #E8E8E8;
            border-bottom: 1px solid #ccc;
            padding: 5px 20px;
        }
        
        .nav a {
            color: #3F51B5;
            text-decoration: none;
            margin-right: 15px;
            font-size: 13px;
        }
        
        .nav a:hover {
            text-decoration: underline;
        }
        
        .content {
            padding: 20px;
        }
        
        .advisory-image-section {
            margin-bottom: 20px;
        }
        
        .advisory-image-box {
            border: 2px solid #999;
            background: #fff;
            padding: 10px;
            text-align: center;
        }
        
        .advisory-image-box img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ccc;
        }
        
        .advisory-caption {
            font-size: 12px;
            color: #666;
            margin-top: 8px;
            font-style: italic;
        }
        
        .warning-section {
            margin-bottom: 20px;
        }
        
        .warning-box {
            border: 3px solid #FF0000;
            background: #FFE6E6;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .warning-header {
            font-size: 18px;
            font-weight: bold;
            color: #CC0000;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .warning-meta {
            font-size: 12px;
            margin-bottom: 10px;
            color: #666;
        }
        
        .warning-text {
            margin: 10px 0;
            line-height: 1.6;
        }
        
        .no-warnings {
            background: #E8F5E9;
            border: 2px solid #4CAF50;
            padding: 15px;
            color: #2E7D32;
            font-weight: bold;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section-title {
            background: #3F51B5;
            color: white;
            padding: 8px 12px;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #999;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        th {
            background: #D9D9D9;
            padding: 8px;
            text-align: left;
            border: 1px solid #999;
            font-weight: bold;
        }
        
        td {
            padding: 8px;
            border: 1px solid #ccc;
            vertical-align: top;
        }
        
        tr:nth-child(even) {
            background: #F5F5F5;
        }
        
        .location-header {
            background: #E8E8E8;
            font-weight: bold;
            font-size: 14px;
            padding: 10px;
        }
        
        .temp-data {
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }
        
        .temp-high {
            color: #CC0000;
        }
        
        .temp-low {
            color: #0066CC;
        }
        
        .earthquake-table td {
            font-size: 12px;
        }
        
        .magnitude {
            font-weight: bold;
            font-size: 14px;
        }
        
        .mag-high {
            color: #CC0000;
        }
        
        .mag-medium {
            color: #FF6600;
        }
        
        .update-time {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
            font-style: italic;
        }
        
        .error {
            background: #FFCCCC;
            border: 2px solid #CC0000;
            padding: 15px;
            margin: 20px 0;
            color: #CC0000;
        }
        
        .info-box {
            background: #F0F0F0;
            border: 1px solid #999;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .info-box h3 {
            font-size: 14px;
            margin-bottom: 8px;
            color: #3F51B5;
        }
        
        .info-box ul {
            margin-left: 20px;
            line-height: 1.8;
        }
        
        .info-box a {
            color: #3F51B5;
            text-decoration: none;
        }
        
        .info-box a:hover {
            text-decoration: underline;
        }
        
        .layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 20px;
        }
        
        .status-online {
            color: #2E7D32;
        }
        
        .status-offline {
            color: #CC0000;
        }
        
        @media (max-width: 1024px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1>NATIONAL WEATHER SERVICE MALAYSIA</h1>
            <div class="subtitle">Klang Valley Weather Forecast Office</div>
        </div>
    </div>
    
    <div class="nav">
        <div class="container">
            <a href="#warnings">CURRENT HAZARDS</a>
            <a href="#forecast">FORECAST</a>
            <a href="#earthquake">EARTHQUAKE DATA</a>
            <a href="<?php echo $forecastApiUrl; ?>" target="_blank">RAW DATA</a>
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
                        <div class="section-title">⚠ WATCHES, WARNINGS & ADVISORIES</div>
                        <?php if ($warningData === null): ?>
                            <div class="error">ERROR: Unable to load warning data from MET Malaysia API</div>
                        <?php elseif (empty($klangValleyWarnings)): ?>
                            <div class="no-warnings">✓ NO ACTIVE WARNINGS OR ADVISORIES FOR KLANG VALLEY AT THIS TIME</div>
                        <?php else: ?>
                            <?php foreach ($klangValleyWarnings as $warning): ?>
                                <?php
                                $issued = isset($warning['warning_issue']['issued']) ? date('l, F j, Y g:i A', strtotime($warning['warning_issue']['issued'])) : 'N/A';
                                $validFrom = isset($warning['valid_from']) ? date('l, F j, Y g:i A', strtotime($warning['valid_from'])) : 'N/A';
                                $validTo = isset($warning['valid_to']) ? date('l, F j, Y g:i A', strtotime($warning['valid_to'])) : 'N/A';
                                ?>
                                <div class="warning-box">
                                    <div class="warning-header">
                                        <?php echo htmlspecialchars($warning['warning_issue']['title_en'] ?: $warning['warning_issue']['title_bm']); ?>
                                    </div>
                                    <div class="warning-meta">
                                        <strong>ISSUED:</strong> <?php echo $issued; ?><br>
                                        <strong>VALID FROM:</strong> <?php echo $validFrom; ?><br>
                                        <strong>VALID TO:</strong> <?php echo $validTo; ?>
                                    </div>
                                    <?php if (!empty($warning['heading_en'])): ?>
                                        <div style="font-weight: bold; margin: 10px 0;">
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
                                            // Skip if we've already seen this date
                                            if (isset($seenDates[$forecast['date']])) {
                                                continue;
                                            }
                                            $seenDates[$forecast['date']] = true;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo date('D, M j', strtotime($forecast['date'])); ?></strong></td>
                                                <td><?php echo htmlspecialchars($forecast['morning_forecast']); ?></td>
                                                <td><?php echo htmlspecialchars($forecast['afternoon_forecast']); ?></td>
                                                <td><?php echo htmlspecialchars($forecast['night_forecast']); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($forecast['summary_forecast']); ?></strong><br>
                                                    <small>(<?php echo htmlspecialchars($forecast['summary_when']); ?>)</small>
                                                </td>
                                                <td class="temp-data">
                                                    <span class="temp-high"><?php echo $forecast['max_temp']; ?>°</span> / 
                                                    <span class="temp-low"><?php echo $forecast['min_temp']; ?>°</span>
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
                                        $mag = $eq['magdefault'] ?? 0;
                                        $magClass = $mag >= 6.0 ? 'mag-high' : ($mag >= 5.0 ? 'mag-medium' : '');
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
                            <li>Kuala Lumpur (WP)</li>
                            <li>Petaling Jaya</li>
                            <li>Shah Alam</li>
                            <li>Subang Jaya</li>
                            <li>Klang</li>
                            <li>Putrajaya (WP)</li>
                            <li>Cyberjaya</li>
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
                            <span class="<?php echo $forecastData ? 'status-online' : 'status-offline'; ?>">
                                <?php echo $forecastData ? '✓ Online' : '✗ Error'; ?>
                            </span><br>
                            <strong>Warnings:</strong> 
                            <span class="<?php echo $warningData ? 'status-online' : 'status-offline'; ?>">
                                <?php echo $warningData ? '✓ Online' : '✗ Error'; ?>
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
                            <li><a href="https://www.met.gov.my/" target="_blank">MET Malaysia Portal</a></li>
                            <li><a href="<?php echo $forecastApiUrl; ?>" target="_blank">Raw Forecast Data</a></li>
                            <li><a href="<?php echo $warningApiUrl; ?>" target="_blank">Raw Warning Data</a></li>
                            <li><a href="<?php echo $earthquakeApiUrl; ?>" target="_blank">Raw Earthquake Data</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
