<?php

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$startHeight = $height = 100; // start height
$maxBlockHeight = 670000;
$heightRange = $maxBlockHeight - $height;
$slowBlocks = array();
$secondsBetweenBlocks = array();

// initialize start block vars
//print_r($bitcoind->getBlockHash($height));exit;
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlockheader($currentBlockHash);
$previousTimestamp = $block->get('time');
$nextBlockHash = $block->get('nextblockhash');

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlockheader($nextBlockHash);
	$timestamp = $block->get('time');
	$secondsSinceLastBlock = $timestamp - $previousTimestamp;

	if (key_exists($secondsSinceLastBlock, $secondsBetweenBlocks)) {
		$secondsBetweenBlocks[$secondsSinceLastBlock]++;
	} else {
		$secondsBetweenBlocks[$secondsSinceLastBlock] = 1;
	}

	// make a note of this anomaly
	if ($secondsSinceLastBlock > 10000) {
		$slowBlocks[$height] = $secondsSinceLastBlock;
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}

	$height++;
	$nextBlockHash = $block->get('nextblockhash');
	$previousTimestamp = $timestamp;
}

// sort by seconds ascending
ksort($secondsBetweenBlocks);

$negativeTimeDeltaCount = 0;
foreach ($secondsBetweenBlocks as $seconds => $count) {
	if ($secondsBetweenBlocks < 0) {
		$negativeTimeDeltaCount++;
	}
	echo "$seconds,$count\n";
}

echo "\nExtremely slow blocks\n";
echo "Block Height,Seconds Delta:\n";
foreach ($slowBlocks as $height => $seconds) {
	echo "$height,$seconds\n";
}

echo "\nThere were $negativeTimeDeltaCount negative delta blocks\n";