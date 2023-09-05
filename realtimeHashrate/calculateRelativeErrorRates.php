<?php
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

// This script ingests the output of aggregateRealtimePoolStats.php
// and compares the value for each block height against Bitcoin Core's
// "getnetworkhashps" RPC endpoint for a variety of trailing number of blocks values

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to CSV hashrate file\n";
    exit;
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
    echo "ERROR: failed to open CSV hashrate file\n";
    exit;
}

$bitcoind = new BitcoinClient('http://qwerty:qwerty@localhost:8332/');
$errorRates = array();
$startTime = time();

// CSV file format: Block Height, Realtime Aggregate Hashrate (EH/s)
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
	$blockHeight = (integer)$data[0];
	$realHashrate = $data[1];

	$percentRemaining = number_format((784000 - $blockHeight) / 34000, 2) * 100;
	$now = time();
	$elapsed = $now - $startTime;
	$timeLeft = $elapsed * $percentRemaining;
	if ($elapsed % 60 == 0) {
		echo "$elapsed $percentRemaining $timeLeft seconds remaining\n";
	}

	for ($trailingBlocks = 1000; $trailingBlocks <= 2000; $trailingBlocks++) {
		$hashrate = $bitcoind->getNetworkHashPS($trailingBlocks, $blockHeight)->get();
		// use hashrate in exahashes with 2 decimals precison
		$exaHashrate = number_format($hashrate / 1E+18, 2, '.', '');
		$errorRate = number_format(abs($realHashrate - $exaHashrate) / $realHashrate, 2, '.', '') * 100;
		$errorRates[$trailingBlocks][$blockHeight] = $errorRate;
	}
}

echo "Trailing Blocks,Block Height,Error %\n";
foreach ($errorRates as $trailingBlocks => $rates) {
	foreach ($rates as $blockHeight => $errorRate) {
		echo "$trailingBlocks,$blockHeight,$errorRate\n";
	}
}