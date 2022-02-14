<?php

// This script reads in a debug.log file produced by Bitcoin Core
// and extracts block heights and how many minutes it took to sync to that point

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to debug log file\n";
    exit;
}

$startTime;
$version;

// output CSV file format: Minutes,Block Height
echo "Minutes Syncing,Block Height\n";


if (($handle = fopen($argv[1], "r")) !== FALSE) {
    // first we must determine which Bitcoin Core version created this log file
    // because the log formats changed a bit over the years
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        if (in_array("version", $data)) {
            $position = array_keys($data, "version")[0];
            // strip non-numeric chars to get the actual number
            $version = preg_replace("/[^0-9\.]/", "", $data[$position + 1]);
        }
    }

    // Bitcoin Core versions prior to X printed timestamp in YYY-MM-DD HH:MM:SS format
    // while later versions used YYYY-MM-DDTHH:MM:SSZ format

    // we must find the first timestamp to determine the start time
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        if (isset($data[0]) && strtotime($data[0])) {
            $startTime = strtotime($data[0]);
            break;
        }
    }

    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        // Bitcoin Core versions prior to v0.9.0 printed block processed lines with "SetBestChain:"
        // while later versions use "UpdateTip:"
        if ($data[1] !== "UpdateTip:" && $data[1] !== "SetBestChain:") {
            continue;
        }
        $height = explode("=", $data[4])[1];
        if ($height % 1000 != 0) {
        	continue;
        }

        $syncTime = floor((strtotime($data[0]) - $startTime) / 60);
        echo "$syncTime,$height\n";
    }
    fclose($handle);
} else {
	echo "ERROR: Failed to open debug.log file\n";
}