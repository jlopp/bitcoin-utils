<?php
// This script ingests the output of outputEstimatedHashratePerBlock.php for 2 trailing block parameters
// and then creates a blended estimate based upon recent estimates for a given block height
// and compares that to the output of aggregateRealtimePoolStats.php to determine the
// average error rate and standard deviation

if (count($argv) < 4 || !$argv[1]) {
    echo "ERROR: first argument must be path to CSV realtime hashrate file\n";
    exit;
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
    echo "ERROR: failed to open CSV realtime hashrate file\n";
    exit;
} else {
	$data = fgetcsv($handle, 1000, ",");
	$startHeight = (integer)$data[0];
}
fclose($handle);

if (count($argv) < 4 || !$argv[2]) {
    echo "ERROR: second argument must be path to CSV hashrate estimate file\n";
    exit;
}

if (($handle = fopen($argv[2], "r")) === FALSE) {
    echo "ERROR: failed to open CSV hashrate estimate file\n";
    exit;
}
fclose($handle);

if (count($argv) < 4 || !$argv[3]) {
    echo "ERROR: second argument must be path to CSV hashrate estimate file\n";
    exit;
}

if (($handle = fopen($argv[3], "r")) === FALSE) {
    echo "ERROR: failed to open CSV hashrate estimate file\n";
    exit;
}
fclose($handle);

// input data
$realHashrates = array();
$shortEstimates = array();
$longEstimates = array();

// calculated results
$blendedEstimates = array();
$errorRates = array();
$stdDeviations = array();

// CSV file format: Block Height, Realtime Aggregate Hashrate (EH/s)
$handle = fopen($argv[1], "r");
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$blockHeight = (integer)$data[0];
	$hashrate = $data[1];
	$realHashrates[$blockHeight] = $hashrate;
}
fclose($handle);

// CSV file format: Block Height, Estimated Hashrate (EH/s)
$handle = fopen($argv[2], "r");
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$blockHeight = (integer)$data[0];
	$hashrate = $data[1];
	$shortEstimates[$blockHeight] = $hashrate;
}
fclose($handle);

// CSV file format: Block Height, Estimated Hashrate (EH/s)
$handle = fopen($argv[3], "r");
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$blockHeight = (integer)$data[0];
	$hashrate = $data[1];
	$longEstimates[$blockHeight] = $hashrate;
}
fclose($handle);

echo "Trailing Blocks,Higher Short Weight,Lower Short Rate,Average Error Rate,Standard Deviation\n";

$trailingBlocks = 10; // test from 10 to 100 trailing blocks
$higherShortWeight = $trailingBlocks; // test from $trailingBlocks to 1000
$lowerShortWeight = $trailingBlocks; // test from $trailingBlocks to 1000
for ($trailingBlocks = 10; $trailingBlocks <= 200; $trailingBlocks++) {
	for ($higherShortWeight = $trailingBlocks; $higherShortWeight <= 1000; $higherShortWeight++) {
		for ($lowerShortWeight = $trailingBlocks; $lowerShortWeight <= 1000; $lowerShortWeight++) {
			// calculate blended hashrate estimate
			foreach ($realHashrates as $blockHeight => $hashrate) {
				// only start $trailingBlocks blocks into our data since we use a window of past $trailingBlocks blocks of estimates
				if ($blockHeight < $startHeight + $trailingBlocks) {
					continue;
				}
				$shortEstimate = $shortEstimates[$blockHeight];
				$longEstimate = $longEstimates[$blockHeight];

				// if current short hashrate estimate is less than 1 std deviation from long estimate, ignore it
				if (abs($longEstimate - $shortEstimate) / $longEstimate < 0.06) {
					$blendedEstimate = $longEstimate;
				} else {
					$shortWeight = 0;

					// find how many of the recent short estimates have also been above/below the long estimate
					// and weight the short estimate
					if ($shortEstimate > $longEstimate) {
						$higher = 0;
						for ($i = $blockHeight - $trailingBlocks; $i < $blockHeight; $i++) {
							if ($shortEstimates[$i] > $longEstimates[$i]) {
								$higher++;
							}
						}
						$shortWeight = $higher / $higherShortWeight;
					} else {
						$lower = 0;
						for ($i = $blockHeight - $trailingBlocks; $i < $blockHeight; $i++) {
							if ($shortEstimates[$i] < $longEstimates[$i]) {
								$lower++;
							}
						}
						// TODO exclude if it was only lower the vast majority of the period
						$shortWeight = $lower / $lowerShortWeight;
					}
					$blendedEstimate = $longEstimate * (1 - $shortWeight) + $shortEstimate * $shortWeight;
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
				//echo "$blockHeight,$hashrate\n";
			//}
			echo "$trailingBlocks,$higherShortWeight,$lowerShortWeight,$average,$stdDeviation\n";
		}
	}
}