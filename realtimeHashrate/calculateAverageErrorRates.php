<?php
// This script ingests the output of calculateRelativeErrorRates.php
// and finds the average error rate for each block target estimate over the whole range of heights

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to CSV error rate file\n";
    exit;
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
    echo "ERROR: failed to open CSV error file\n";
    exit;
}

$trailingBlocks = 1000;
$averageError = array();
$errorRates = array();

// CSV file format: Trailing Blocks,Block Height,Error %
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$currentTrailingBlocks = (integer)$data[0];
	$errorRate = $data[2];

	// keep reading in data until Trailing Blocks changes
	if ($currentTrailingBlocks == $trailingBlocks) {
		$errorRates[] = $errorRate;
	} else {
		// calculate and store average
		$average = number_format(array_sum($errorRates) / count($errorRates), 2);
		$averageError[$trailingBlocks] = $average;
		// reset placeholders
		$trailingBlocks = $currentTrailingBlocks;
		$errorRates = array();
		$errorRates[] = $errorRate;
	}
}
// calculate final bucket
$average = number_format(array_sum($errorRates) / count($errorRates), 2);
$averageError[$trailingBlocks] = $average;

echo "Trailing Blocks,Average Error %\n";
foreach ($averageError as $trailingBlocks => $average) {
	echo "$trailingBlocks,$average\n";
}