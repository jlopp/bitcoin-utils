<?php

// This script reads in a debug.log file produced by Bitcoin Core
// and extracts block heights and how many minutes it took to sync to that point

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to debug log file\n";
    exit;
}

if (count($argv) < 3 || !is_numeric($argv[2]) || $argv[2] < 0) {
    echo "ERROR: second argument must be a positive integer (max block height)\n";
    exit;
}

$startTime;
$version;
$maxHeight = $argv[2];

// output CSV file format: Minutes,Block Height
echo "Minutes Syncing,Block Height\n";

if (($handle = fopen($argv[1], "r")) !== FALSE) {
    // first we must determine which Bitcoin Core version created this log file
    // because the log formats changed a bit over the years
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        if (in_array("version", $data) && in_array("Bitcoin", $data)) {
            $position = array_keys($data, "version")[0];

            // strip non-numeric chars to get the actual number
            $version = preg_replace("/[^0-9\.]/", "", $data[$position + 1]);

            // if there is more than one decimal, truncate decimals so that we have a real number
            preg_match_all('/\./', $version, $matches, PREG_OFFSET_CAPTURE);
            if (array_key_exists(0, $matches) && array_key_exists(1, $matches[0]) && array_key_exists(1, $matches[0][1])) {
                $version = substr($version, $matches[0][0][1] + 1, $matches[0][1][1] - $matches[0][0][1] - 1);
            }
            break;
        }
    }

    // Bitcoin Core versions prior to v0.18 printed timestamp in YYY-MM-DD HH:MM:SS format
    // while later versions used YYYY-MM-DDTHH:MM:SSZ format
    // This also shifts which CSV field various values end up in
    $heightField = $version < 18 ? 6 : 4;

    // we must find the first timestamp to determine the start time
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        if ($version < 18) {
            if (isset($data[0]) && strtotime($data[0] . " " . $data[1])) {
                $startTime = strtotime($data[0] . " " . $data[1]);
                break;
            }
        } else if (isset($data[0]) && strtotime($data[0])) {
            $startTime = strtotime($data[0]);
            break;
        }
    }

    // Find the actual block processed timestamps and calculate time elapsed
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        // Bitcoin Core versions prior to v0.9.0 printed block processed lines with "SetBestChain:"
        // while later versions use "UpdateTip:"
        if ($version < 9 && $data[2] !== "SetBestChain:") {
            continue;
        } else if ($version >= 9 && $version < 12 && $data[2] !== "UpdateTip:") {
            continue;
        } else if ($version >= 12 && $data[1] !== "UpdateTip:") {
            continue;
        }

        $height = explode("=", $data[$heightField])[1];
        if ($height % 1000 != 0) {
        	continue;
        }

        if ($height > $maxHeight) {
            break;
        }

        if ($version < 12) {
            $syncTime = floor((strtotime($data[0] . " " . $data[1]) - $startTime) / 60);
        } else {
            $syncTime = floor((strtotime($data[0]) - $startTime) / 60);
        }

        echo "$syncTime,$height\n";
    }
    fclose($handle);
} else {
	echo "ERROR: Failed to open log file\n";
}