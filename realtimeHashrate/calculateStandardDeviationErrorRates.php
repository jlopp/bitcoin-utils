<?php
// This script ingests the output of calculateRelativeErrorRates.php
// and finds the standard deviation for each block target estimate over the whole range of heights

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to CSV error rate file\n";
    exit;
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
    echo "ERROR: failed to open CSV error file\n";
    exit;
}

$trailingBlocks = 1;
$stdDeviation = array();
$errorRates = array();

// CSV file format: Trailing Blocks,Block Height,Error %
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$currentTrailingBlocks = (integer)$data[0];
	$errorRate = $data[2];

	// keep reading in data until Trailing Blocks changes
	if ($currentTrailingBlocks == $trailingBlocks) {
		$errorRates[] = $errorRate;
	} else {
		// calculate and store std deviation
		$sum = array_sum($errorRates);
		$count = count($errorRates);
		$average = $sum / $count;
		$deviationSquares = array();
		foreach ($errorRates as $errorRate) {
			$deviationSquares[] = ($errorRate - $average) ** 2;
		}

		$stdDeviation[$trailingBlocks] = sqrt(array_sum($deviationSquares) / $count);

		// reset placeholders
		$trailingBlocks = $currentTrailingBlocks;
		$errorRates = array();
		$errorRates[] = $errorRate;
	}
}
// calculate final bucket
$sum = array_sum($errorRates);
$count = count($errorRates);
$average = $sum / $count;
$deviationSquares = array();
foreach ($errorRates as $errorRate) {
	$deviationSquares[] = ($errorRate - $average) ** 2;
}

$stdDeviation[$trailingBlocks] = sqrt(array_sum($deviationSquares) / $count);

echo "Trailing Blocks,Standard Deviation\n";
foreach ($stdDeviation as $trailingBlocks => $deviation) {
	echo "$trailingBlocks,$deviation\n";
}