<?php
// This script examines all transactions in a given block height range
// and counts how many had over 5 inputs and the same number of outputs
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:password@localhost:8332/');
$startHeight = $height = 817000; // start height
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$heightRange = $maxBlockHeight - $height;
$txByBlock = array();
$wuByBlock = array();

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlock($currentBlockHash, 2);
$height++;

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlock($block->get('nextblockhash'), 2);
	$txByBlock[$height] = 0;
	$wuByBlock[$height] = 0;

	foreach ($block->get('tx') as $transaction) {
		if (count($transaction['vin']) >= 5 && count($transaction['vin']) == count($transaction['vout'])) {
			$txByBlock[$height]++;
			$wuByBlock[$height] += $transaction['weight'];
		} else if (count($transaction['vin']) >= 50 && count($transaction['vout']) >= 50) {
			$txByBlock[$height]++;			
			$wuByBlock[$height] += $transaction['weight'];
		}
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}
	$height++;
}

ksort($txByBlock);

echo "Block Height, CoinJoin Transactions, CoinJoin Weight Units\n";
foreach ($txByBlock as $height => $count) {
	echo "$height,$count," . $weByBlock[$height] . "\n";
}
