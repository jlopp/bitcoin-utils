<?php

// This script returns the result of blending many hashrate estimates together
// in order to create a more accurate estimate of the total network hashrate
// For more details, read this article: 
if (count($argv) < 2 || !$argv[1] || !is_numeric($argv[1])) {
    echo "ERROR: argument 1 must be an integer\n";
    exit;
}

// uses https://github.com/denpamusic/php-bitcoinrpc
require 'vendor/autoload.php';
use Denpa\Bitcoin\Client as BitcoinClient;
$bitcoind = new BitcoinClient('http://user:pass@localhost:8332/');

$blockHeight = (int)$argv[1];

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

// Start off giving a 5% weight to the 1,000 trailing block estimate
$longEstimate = $bitcoind->getNetworkHashPS(1000, $blockHeight)->get();
$blendedEstimate = $longEstimate * 0.05;

for ($trailingBlocks = 100; $trailingBlocks < 1000; $trailingBlocks += 100) {
	$shortEstimate = $bitcoind->getNetworkHashPS($trailingBlocks, $blockHeight)->get();

	// if current short hashrate estimate is less than 1 std deviation from long estimate, ignore it
	if (abs($longEstimate - $shortEstimate) < $estimateStdDev[$trailingBlocks]) {
		$blendedEstimate += $longEstimate * 0.1;
	} else {
		// find how many of the recent ($trailingBlocks) short estimates have also been above/below the long estimate
		// and weight the short estimate
		if ($shortEstimate > $longEstimate) {
			$higher = 0;
			for ($height = $blockHeight - $trailingBlocks; $height < $blockHeight; $height++) {
				if ($bitcoind->getNetworkHashPS(1000, $height)->get() > $longEstimate) {
					$higher++;
				}
			}
			$shortWeight = $higher / $trailingBlocks;
		} else {
			$shortWeight = 0;
		}
		$blendedEstimate += ($longEstimate * (1 - $shortWeight) + $shortEstimate * $shortWeight) * 0.1;
	}
}

echo "$blendedEstimate\n";