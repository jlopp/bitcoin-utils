<?php

// This script reads in a CSV of twitter account data
// and outputs the handles of accounts with high attention and insider scores

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to CSV file\n";
    exit;
}

// the score to use for filtering accounts
if (count($argv) < 3 || !is_numeric($argv[2]) || $argv[2] < 0 || $argv[2] > 1) {
    echo "ERROR: second argument must be a decimal between 0 and 1\n";
    exit;
}

$threshold = $argv[2];

echo "Account ID,Account Handle\n";
if (($handle = fopen($argv[1], "r")) !== FALSE) {
    // skip first row
    fgetcsv($handle, 1000, ",");

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (isset($data[12]) && isset($data[13]) && isset($data[17])) {
            if ($data[12] >= $threshold && $data[13] >= $threshold && ($data[16] >= 0.15 || $data[17] >= 0.15)) {
                // filter out BCH handles
                if (stristr($data[3], "bch") || stristr($data[3], "bitcoincash")) {
                    continue;
                }
                echo $data[1] . "," . $data[3] . "\n";
            }
        }
    }
    fclose($handle);
} else {
	echo "ERROR: Failed to open CSV file\n";
}