<?php
// This script finds blocks that are mined with no transactions even though
// There were likely unconfirmed transactions in the mempool waiting to be mined
// The mempool assumption is made by checking the weight of the parent and child blocks
// and if both weights are > 95% of the max weight, we assume the mempool was not empty

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:password@localhost:8332/');
$startHeight = $height = 355000; // start height
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$heightRange = $maxBlockHeight - $height;
$emptyBlocks = array();
$monthCounts = array();
$secondsBetweenBlocks = array();

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlockheader($currentBlockHash);
$previousTimestamp = $block->get('time');
$nextBlockHash = $block->get('nextblockhash');

function getMiningPool($blockHeight) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://api.blockchair.com/bitcoin/dashboards/block/" . $blockHeight);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	$result = curl_exec($ch);
	curl_close($ch);
	$json = json_decode($result);
	if (isset($json->data->$blockHeight->block->guessed_miner)) {
		return $json->data->$blockHeight->block->guessed_miner;
	} else {
		return "Unknown";
	}
}

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlockheader($nextBlockHash);
	$timestamp = $block->get('time');
	$height = $block->get('height');
	$transactionCount = $block->get('nTx');

	if ($transactionCount == 1) {
		$secondsSinceLastBlock = $timestamp - $previousTimestamp;
		// If the weight of the previous and next blocks is near max weight, this block was not empty due to the mempool being empty
		$prevBlockWeight = $bitcoind->getBlock($bitcoind->getBlockhash($height - 1)->get())->get('weight');
		$nextBlockWeight = $bitcoind->getBlock($block->get('nextblockhash'))->get('weight');
		if ($prevBlockWeight > 3800000 && $nextBlockWeight > 3800000) {
			$date = gmdate("m-Y",$timestamp);
			$emptyBlocks[$height] = array($secondsSinceLastBlock, $date, getMiningPool($height));
			if (key_exists($date, $monthCounts)) {
				$monthCounts[$date]++;
			} else {
				$monthCounts[$date] = 1;
			}
		}
	}


	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}

	$nextBlockHash = $block->get('nextblockhash');
	$previousTimestamp = $timestamp;
}

// sort by height ascending
ksort($emptyBlocks);

echo "\nEmpty blocks\n";
foreach ($emptyBlocks as $emptyBlockHeight => $values) {
	echo "$emptyBlockHeight," . implode(",", $values) ."\n";
}

echo "\nEmpty Blocks Per Month\n";
// sort by height ascending
ksort($monthCounts);

echo "\nEmpty blocks\n";
foreach ($monthCounts as $month => $count) {
	echo "$month,$count\n";
}
