<?php

// This script takes a transaction ID as its first argument and pulls the txn from the blockstream explorer API
// It then searches backwards to find the originating coinbase transaction(s),
// and prints out the block height(s) in which they were mined
// If the second argument is 0, it only looks at this txn's inputs.
// If set to 1, it keeps going recursively until the coinbase transactions are all found.

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/tx/" . $argv[1]);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$blockHeights = array();

$result = curl_exec($ch);
$txJson = json_decode($result);
findCoinbaseInputs($txJson, (bool)$argv[2]);

// Sort and print the results
sort($blockHeights);
foreach ($blockHeights as $height) {
	echo $height . "\n";
}

function findCoinbaseInputs($txJson, $recursive) {
	global $blockHeights, $ch;

	foreach ($txJson->vin as $input) {
		curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/tx/" . $input->txid);
		$inputResult = curl_exec($ch);
		$inputTxJson = json_decode($inputResult);
		if ($inputTxJson->vin[0]->is_coinbase) {
			$blockHeights[$inputTxJson->status->block_height] = $inputTxJson->status->block_height;
		} else if ($recursive) {
			findCoinbaseInputs($inputTxJson, $recursive);
		}
	}
}