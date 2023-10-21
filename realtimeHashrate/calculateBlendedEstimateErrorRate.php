<?php
// This script ingests the output of outputEstimatedHashratePerBlock.php for 10 trailing block parameters
// (100,200,300,400,500,600,700,800,900,1100) and then generates many blended estimates based upon recent
// estimates for a given block height and compares that to the output of aggregateRealtimePoolStats.php
// to determine the average error rate and standard deviation

for ($i = 1; $i <= 10; $i++) {
	if (count($argv) < $i + 1 || !$argv[$i]) {
	    echo "ERROR: argument $i must be path to CSV hashrate estimate file\n";
	    exit;
	}

	if (($handle = fopen($argv[$i], "r")) === FALSE) {
	    echo "ERROR: failed to open CSV hashrate estimate file $i\n";
	    exit;
	} else {
		$data = fgetcsv($handle, 1000, ",");
		$startHeight = (integer)$data[0];
	}
	fclose($handle);
}

if (count($argv) < 12 || !$argv[11]) {
    echo "ERROR: 11th argument must be path to realtime hashrate CSV file\n";
    exit;
}

if (($handle = fopen($argv[11], "r")) === FALSE) {
    echo "ERROR: failed to open realtime hashrate CSV\n";
    exit;
}
fclose($handle);

// input data
$realHashrates = array();
$estimates = array();
$estimateStdDev = array(100 => 6.23,
						200 => 4.53,
						300 => 3.85,
						400 => 3.48,
						500 => 3.24,
						600 => 3.11,
						700 => 3.04,
						800 => 3.04,
						900 => 3.01
						);

// calculated results
$blendedEstimates = array();
$errorRates = array();
$stdDeviations = array();

// CSV file format: Block Height, Realtime Aggregate Hashrate (EH/s)
$handle = fopen($argv[11], "r");
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$blockHeight = (integer)$data[0];
	$hashrate = $data[1];
	$realHashrates[$blockHeight] = $hashrate;
}
fclose($handle);

// populate maps of all estimates
for ($i = 1; $i <= 10; $i++) {
	// CSV file format: Block Height, Estimated Hashrate (EH/s)
	$handle = fopen($argv[$i], "r");
	while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
		$blockHeight = (integer)$data[0];
		$hashrate = $data[1];
		$estimates[$i * 100][$blockHeight] = $hashrate;
	}
	fclose($handle);
}

// calculate blended hashrate estimate
foreach ($realHashrates as $blockHeight => $hashrate) {
	// only start $trailingBlocks blocks into our data since we use a window of past $trailingBlocks blocks of estimates
	if ($blockHeight < $startHeight + 900) {
		continue;
	}

	$blendedEstimate = $estimates[1000][$blockHeight] * 0.10;
	for ($i = 1; $i < 10; $i++) {
		$shortEstimate = $estimates[$i * 100][$blockHeight];
		$longEstimate = $estimates[1000][$blockHeight];

		// if current short hashrate estimate is less than 1 std deviation from long estimate, ignore it
		if (abs($longEstimate - $shortEstimate) < $estimateStdDev[$i * 100]) {
			$blendedEstimate += $longEstimate * 0.1;
		} else {
			// find how many of the recent short estimates have also been above/below the long estimate
			// and weight the short estimate
			if ($shortEstimate > $longEstimate) {
				$higher = 0;
				for ($height = $blockHeight - $i * 100; $height < $blockHeight; $height++) {
					if ($estimates[$i * 100][$height] > $estimates[1000][$height]) {
						$higher++;
					}
				}
				$shortWeight = $higher / ($i * 100);
			} else {
				$lower = 0;
				for ($height = $blockHeight - $i * 100; $height < $blockHeight; $height++) {
					if ($estimates[$i * 100][$height] < $estimates[1000][$height]) {
						$lower++;
					}
				}

				//$shortWeight = $lower / ($i * 100);
				$shortWeight = 0;
			}
			$blendedEstimate += ($longEstimate * (1 - $shortWeight) + $shortEstimate * $shortWeight) * 0.1;
		}
	}
	$blendedEstimates[$blockHeight] = $blendedEstimate;

	// calculate the relative error rates for the new blended estimates
	$errorRate = number_format(abs($realHashrates[$blockHeight] - $blendedEstimate) / $realHashrates[$blockHeight], 2, '.', '') * 100;
	$errorRates[$blockHeight] = $errorRate;
}

// calculate the average error for the new blended estimates
$sum = array_sum($errorRates);
$count = count($errorRates);
$average = $sum / $count;

// calculate the standard deviation for the new blended estimates
$deviationSquares = array();
foreach ($errorRates as $errorRate) {
	$deviationSquares[] = ($errorRate - $average) ** 2;
}

$stdDeviation = sqrt(array_sum($deviationSquares) / $count);
//foreach ($blendedEstimates as $blockHeight => $hashrate) {
//	echo "$blockHeight,$hashrate\n";
//}
echo "Average Error Rate,Standard Deviation\n";
echo "$average,$stdDeviation\n";