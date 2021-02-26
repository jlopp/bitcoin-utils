<?php

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$startHeight = $height = 100; // start height
$maxBlockHeight = $block->get('getblockchaininfo')->get('blocks');
$heightRange = $maxBlockHeight - $height;
$slowBlocks = array();
$secondsBetweenBlocks = array();
$negativeBlockHeights = array();

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

	// make a note of this anomaly
	if ($secondsSinceLastBlock < 0) {
		$negativeBlockHeights[$height] = $secondsSinceLastBlock;
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
// sort by block height ascending
ksort($negativeBlockHeights);

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

echo "\nNegative time delta blocks\n";
echo "Block Height,Seconds Delta:\n";
foreach ($negativeBlockHeights as $height => $seconds) {
	echo "$height,$seconds\n";
}

echo "\nThere were $negativeTimeDeltaCount negative delta blocks\n";