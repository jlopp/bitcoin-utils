<?php

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:password@localhost:18332/');
$startHeight = $height = 2560000; // start height
$maxBlockHeight = $bitcoind->getBlockChainInfo()->get('blocks');
$heightRange = $maxBlockHeight - $height;
$dayCounts = array();

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlockheader($currentBlockHash);
$nextBlockHash = $block->get('nextblockhash');

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlockheader($nextBlockHash);
	$timestamp = $block->get('time');
	$dmy = gmdate("m-d-Y", $timestamp);
	if (key_exists($dmy, $dayCounts)) {
		$dayCounts[$dmy]++;
	} else {
		$dayCounts[$dmy] = 1;
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}

	$height++;
	$nextBlockHash = $block->get('nextblockhash');
}

echo "Date,Blocks Mined\n";
foreach ($dayCounts as $date => $blocks) {
	echo "$date,$blocks\n";
}