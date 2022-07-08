<?php

// This script takes a CSV of transaction IDs and fees and checks them against a local node
// Note that the local node must have txIndex enabled

if ($argv[1] !== "mainnet" && $argv[1] !== "testnet") {
	echo "ERROR: first argument must be mainnet or testnet\n";
}

if (!file_exists($argv[2])) {
	echo "ERROR: second argument must be a valid CSV file\n";
}

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:password@localhost:8332/');

$readLines = explode("\n", file_get_contents($argv[2]));

foreach ($readLines as $line) {
	$txHash = explode("|", $line)[0];
	$txFee = explode("|", $line)[1];

	try {
		$transaction = $bitcoind->getRawtransaction($txHash, true)->get();
	} catch (Exception $error) {
		echo "ERROR: tx hash $txHash does not exist!\n";
		continue;
	}

	// Calculate transaction fee paid (aggregate output value - aggregate input value)
	$inputValue = $outputValue = 0;
	foreach ($transaction['vin'] as $input) {
		$inputTx = $bitcoind->getRawtransaction($input['txid'], true)->get();
		$inputValue += $inputTx['vout'][$input['vout']]['value'];
	}
	foreach ($transaction['vout'] as $output) {
		$outputValue += $output['value'];
	}
	$fee = round(($inputValue - $outputValue) * 100_000_000);

	if ($txFee != $fee) {
		echo "ERROR: $txHash expected tx fee $txFee, got $fee \n";
	}
}

