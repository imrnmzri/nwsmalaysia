<!DOCTYPE html>
<html>
<head>
    <title>Weather Service</title>
</head>
<body>

<h1>WEATHER SERVICE</h1>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$locationFilter = "Ds058";

$forecastApiUrl = "https://api.data.gov.my/weather/forecast/";
$warningApiUrl = "https://api.data.gov.my/weather/warning/";

// Fetch forecast data
$forecastResponse = file_get_contents($forecastApiUrl);
if ($forecastResponse === false) {
    die("Error fetching weather data.");
}
$forecastData = json_decode($forecastResponse, true);
if ($forecastData === null) {
    die("JSON Decode Error: " . json_last_error_msg());
}

// Filter forecasts by location
$filteredForecasts = array_filter($forecastData, function($forecast) use ($locationFilter) {
    return isset($forecast['location']['location_id']) &&
           $forecast['location']['location_id'] === $locationFilter;
});

// Display forecasts
if (!empty($filteredForecasts)) {
    echo "<h2>Weather Forecast for $locationFilter</h2><ul>";
    foreach ($filteredForecasts as $forecast) {
        echo "<li>";
        echo "<strong>Location:</strong> " . $forecast['location']['location_name'] . "<br>";
        echo "<strong>Date:</strong> " . $forecast['date'] . "<br>";
        echo "<strong>Morning:</strong> " . $forecast['morning_forecast'] . "<br>";
        echo "<strong>Afternoon:</strong> " . $forecast['afternoon_forecast'] . "<br>";
        echo "<strong>Night:</strong> " . $forecast['night_forecast'] . "<br>";
        echo "<strong>Summary:</strong> " . $forecast['summary_forecast'] . " (" . $forecast['summary_when'] . ")<br>";
        echo "<strong>Temperature:</strong> Min " . $forecast['min_temp'] . "°C, Max " . $forecast['max_temp'] . "°C<br>";
        echo "</li><hr>";
    }
    echo "</ul>";
} else {
    echo "No forecast found for location $locationFilter.";
}

// Fetch warning data
$warningResponse = file_get_contents($warningApiUrl);
if ($warningResponse === false) {
    die("Error fetching warning data.");
}
$warningData = json_decode($warningResponse, true);
if ($warningData === null) {
    die("JSON Decode Error: " . json_last_error_msg());
}

// Filter warnings for Kuala Lumpur
$klWarnings = array_filter($warningData, function($warning) {
    return (isset($warning['text_en']) && str_contains($warning['text_en'], "Kuala Lumpur")) ||
           (isset($warning['text_bm']) && str_contains($warning['text_bm'], "Kuala Lumpur")) ||
           (isset($warning['text_bm']) && str_contains($warning['text_bm'], "WP Kuala Lumpur")) ||
           (isset($warning['text_en']) && str_contains($warning['text_en'], "FT Kuala Lumpur"));
});

// Display warnings
if (!empty($klWarnings)) {
    echo "<h2>Weather Warnings for Kuala Lumpur</h2><ul>";
    foreach ($klWarnings as $warning) {
        echo "<li>";
        echo "<strong>Issued:</strong> " . $warning['warning_issue']['issued'] . "<br>";
        echo "<strong>Title (EN):</strong> " . $warning['warning_issue']['title_en'] . "<br>";
        echo "<strong>Title (BM):</strong> " . $warning['warning_issue']['title_bm'] . "<br>";
        echo "<strong>Warning (EN):</strong> " . $warning['text_en'] . "<br>";
        echo "<strong>Warning (BM):</strong> " . $warning['text_bm'] . "<br>";
        echo "<strong>Valid From:</strong> " . $warning['valid_from'] . "<br>";
        echo "<strong>Valid To:</strong> " . $warning['valid_to'] . "<br>";
        echo "</li><hr>";
    }
    echo "</ul>";
} else {
    echo "No warnings for Kuala Lumpur at the moment.";
}
?>

</body>
</html>

