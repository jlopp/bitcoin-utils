<?php
// This script calculates a variety of hashrate estimates for different
// trailing number of blocks across a given range of block heights
// in order to show the variance in hashrate estimates

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$startHeight = $height = (int)$argv[1]; // start height
$maxBlockHeight = (int)$argv[2];
$heightRange = $maxBlockHeight - $height;
$estimates = array();
$trailingBlocks = array(1,2,3,4,5,6,7,8,9,
						10,20,30,40,50,60,70,80,90,
						100,200,300,400,500,600,700,800,900,
						1000,2000,3000,4000,5000,6000,7000,8000,9000,10000);

// initialize results arrays
for ($i = $startHeight; $i <= $maxBlockHeight; $i++) {
	$estimates[$i] = array();
}

while ($height <= $maxBlockHeight) {
	// loop through all the desired trailing block ranges
	foreach ($trailingBlocks as $target) {
		$hashrate = $bitcoind->getNetworkHashPS($target, $height)->get();
		// store hashrate in exahashes with 2 decimals precison
		$estimates[$height][$target] = number_format($hashrate / 1E+18, 2);
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}

	$height++;
}

// sort by height ascending
ksort($estimates);

echo "\nBlock Height|1|2|3|4|5|6|7|8|9|10|20|30|40|50|60|70|80|90|100|200|300|400|500|600|700|800|900|1000|2000|3000|4000|5000|6000|7000|8000|9000|10000\n";
foreach ($estimates as $estimateHeight => $results) {
	echo $estimateHeight;
	foreach ($results as $target => $hashrate) {
		echo "|$hashrate";
	}
	echo "\n";
}