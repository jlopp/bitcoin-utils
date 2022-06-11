<?php
// This script examines all transactions in a given block height range
// and counts how many had over 50 inputs, categorizing them by segwit or nonsegwit spends
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$startHeight = $height = 480000; // start height
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$heightRange = $maxBlockHeight - $height;
$txByBlock = array();
$segwitTxByBlock = array();

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlock($currentBlockHash, 2);
$height++;

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlock($block->get('nextblockhash'), 2);
	$txByBlock[$height] = $segwitTxByBlock[$height] = 0;

	foreach ($block->get('tx') as $transaction) {
		if (count($transaction['vin']) > 50) {
			// if txid !== hash then it spends segwit inputs
			if ($transaction['txid'] != $transaction['hash']) {
				$segwitTxByBlock[$height]++;
			} else {
				$txByBlock[$height]++;
			}
		}
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}
	$height++;
}

ksort($txByBlock);
ksort($segwitTxByBlock);
$legacyTransactions = $segwitTransactions = 0;

echo "Block Height,Legacy Transactions, Segwit Transactions:\n";
foreach ($txByBlock as $height => $count) {
    if ($height % 1000 != 0) {
       	$legacyTransactions += $count;
       	$segwitTransactions += $segwitTxByBlock[$height];
    } else {
		echo "$height,$legacyTransactions,$segwitTransactions\n";
    	$legacyTransactions = $segwitTransactions = 0;
    }
}
