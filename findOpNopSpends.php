<?php

// This script searches for all transactions known to include OP_NOP opcodes in their redeem scripts
// up to block height 200,000
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$startHeight = $height = 0; // start height
$maxBlockHeight = 200000;
$heightRange = $maxBlockHeight - $height;
$spamBlockHeights = array();
$spamBlockValues = array();
$spamBlockFees = array();
$spamBlockSize = array();

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlock($currentBlockHash, 2);
$height++;

function set_precision($value) {
	return sprintf('%.8F',$value);
}

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlock($block->get('nextblockhash'), 2);

	foreach ($block->get('tx') as $transaction) {
		// Ignore coinbase transactions
		if (isset($transaction['vin'][0]['coinbase'])) {
			continue;
		}

		// Search for outputs containing OP_NOPs in the scriptPubKey asm
		foreach ($transaction['vout'] as $output) {
			if (strpos($output['scriptPubKey']['asm'], "OP_NOP") !== FALSE || strpos($output['scriptPubKey']['asm'], "OP_CHECKLOCKTIMEVERIFY") !== FALSE  || strpos($output['scriptPubKey']['asm'], "OP_CHECKSEQUENCEVERIFY") !== FALSE ) {
				echo "Block Height: $height, txid: " . $transaction['txid'] . "\n";
			}
		}
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}
	$height++;
}