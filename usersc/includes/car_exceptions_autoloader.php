<?php

/**
 * Autoloader for Car-related exception classes
 * 
 * This autoloader handles the loading of custom Car exception classes
 * to keep them organized and automatically available when needed.
 */

// Register autoloader for Car exceptions
spl_autoload_register(function ($class_name) {
    // List of Car exception classes and utility classes
    $car_exceptions = [
        'CarCreationException',
        'CarNotFoundException',
        'CarValidationException',
        'ImageProcessingException',
        'CarTransferException',
        'CarMergeException',
        'CarDeletionException',
        'CarErrorMessages'
    ];
    
    // Check if the requested class is one of our Car exceptions
    if (in_array($class_name, $car_exceptions)) {
        global $abs_us_root, $us_url_root;

        // CarErrorMessages is in classes directory, others are in exceptions directory
        if ($class_name === 'CarErrorMessages') {
            $file_path = $abs_us_root . $us_url_root . 'usersc/classes/' . $class_name . '.php';
        } else {
            $file_path = $abs_us_root . $us_url_root . 'usersc/exceptions/' . $class_name . '.php';
        }

        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
});