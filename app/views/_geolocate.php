<?php
require_once '../users/init.php';

/* Sets variables
$fields['lat']
$fields['lon']
*/

$fields = array();

// Only attempt geocoding if we have valid location data
if (!empty($city) && !empty($country)) {
    $address = trim($city . "," . $state . "," . $country);
    // url encode the address
    $address = urlencode($address);

    // get latitude, longitude
    $data_arr = geocode($address);
    if ($data_arr !== false) {
        $fields['lat'] = round($data_arr[0], 4);
        $fields['lon'] = round($data_arr[1], 4);
        logger(1, 'Geocode', 'Successfully geocoded address: ' . $address . ' -> ' . $fields['lat'] . ',' . $fields['lon']);
    } else {
        logger(1, 'Geocode', 'Failed to geocode address: ' . $address . ' (API key restrictions or network issue)');
        // Don't set lat/lon fields if geocoding fails - preserve existing values
    }
} else {
    logger(1, 'Geocode', 'Insufficient location data provided for geocoding (city: ' . ($city ?? 'null') . ', country: ' . ($country ?? 'null') . ')');
}

function geocode($address)
{
    global $settings;
    
    // url encode the address
    $address = urlencode($address);
    logger(1, 'Geocode', 'Attempting to geocode: ' . $address);

    // google map geocode api url
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$settings->elan_google_geo_key}";

    // Use cURL for better error handling if available, fallback to file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ElanRegistry/2.3');
        curl_setopt($ch, CURLOPT_REFERER, 'https://elanregistry.org/');
        
        $resp_json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($resp_json === false) {
            logger(1, 'Geocode', 'cURL request failed: ' . $curl_error);
            return false;
        }
        
        if ($http_code !== 200) {
            logger(1, 'Geocode', 'HTTP error: ' . $http_code);
            return false;
        }
    } else {
        // Fallback to file_get_contents with context
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'ElanRegistry/2.3',
                'header' => "Referer: https://elanregistry.org/\r\n"
            ]
        ]);
        
        $resp_json = file_get_contents($url, false, $context);
        
        if ($resp_json === false) {
            logger(1, 'Geocode', 'file_get_contents failed for geocoding request');
            return false;
        }
    }

    logger(1, 'Geocode', 'API response received: ' . substr($resp_json, 0, 200) . '...');

    // decode the json
    $resp = json_decode($resp_json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logger(1, 'Geocode', 'JSON decode error: ' . json_last_error_msg());
        return false;
    }

    // response status will be 'OK', if able to geocode given address
    if ($resp['status'] == 'OK') {
        // get the important data
        $lati = isset($resp['results'][0]['geometry']['location']['lat']) ? $resp['results'][0]['geometry']['location']['lat'] : "";
        $longi = isset($resp['results'][0]['geometry']['location']['lng']) ? $resp['results'][0]['geometry']['location']['lng'] : "";

        // verify if data is complete
        if ($lati && $longi) {
            logger(1, 'Geocode', 'Successfully geocoded to: ' . $lati . ', ' . $longi);
            
            // put the data in the array
            $data_arr = array();
            array_push($data_arr, $lati, $longi);
            return $data_arr;
        } else {
            logger(1, 'Geocode', 'Geocoding returned OK but coordinates are missing');
            return false;
        }
    } else {
        $error_msg = 'Geocoding failed with status: ' . ($resp['status'] ?? 'UNKNOWN');
        if (isset($resp['error_message'])) {
            $error_msg .= ', error: ' . $resp['error_message'];
        }
        logger(1, 'Geocode', $error_msg);
        return false;
    }
}
