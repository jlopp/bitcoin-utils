<?php

// This script reads in a CSV of block epochs with aggregate block weight and sats / WU
// and runs an experimental block size algorithm across them to output what the next
// period's max block size would be

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to CSV file\n";
    exit;
}

$weights = array();

echo "Height,New Max Weight\n";
if (($handle = fopen($argv[1], "r")) !== FALSE) {
    // skip first row
    fgetcsv($handle, 1000, ",");

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $weights[] = array(
            "height" => $data[0],
            "weight" => $data[1],
            "feerate" => $data[2]
        );
    }
    fclose($handle);
} else {
	echo "ERROR: Failed to open CSV file\n";
}

$currentDynamicSize = 4_000_000;

foreach ($weights as $index => $weight) {
    $averageWeight = round($weight["weight"] / 1000);
    if ($averageWeight < $currentDynamicSize * 0.95) {
        $feerateDelta = $averageWeight / $currentDynamicSize;
    } else if ($weight["feerate"] <= 10) {
        $feerateDelta = 0.95;
    // determine relative delta between previous epoch's sats/WU and current epoch
    } else if ($weights[$index - 1]["feerate"] < 10) {
        $feerateDelta = sqrt(($weight["feerate"] - 10) / 10);
    } else if ($weight["feerate"] > $weights[$index - 1]["feerate"]) { // fee rate increased
        $feerateDelta = 1 + sqrt(abs(($weight["feerate"] - $weights[$index - 1]["feerate"]) / $weights[$index - 1]["feerate"]));
    } else { // fee rate decreased
        $feerateDelta = 1 - sqrt(abs(($weight["feerate"] - $weights[$index - 1]["feerate"]) / $weights[$index - 1]["feerate"]));
    }

    // set safety boundaries
    // delta must be >= 0.5 and <= 2
    if ($feerateDelta < 0.5) {
        $feerateDelta = 0.5;
    } else if ($feerateDelta > 2) {
        $feerateDelta = 2;
    }

    $currentDynamicSize = round($currentDynamicSize * $feerateDelta);
    if ($currentDynamicSize < 400_000) {
        $currentDynamicSize = 400_000;
    }
    echo $weight["height"] . ',' . $currentDynamicSize . "\n";
}