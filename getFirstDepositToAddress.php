<?php

// This script takes a bitcoin address as its first argument and pulls the deposit history from the blockstream explorer API
// It then outputs the first deposit transaction ID

$address = $argv[1];
if (empty($address)) {
	echo "ERROR: first argument must be bitcoin address\n";
	exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$oldestDepositTxId = NULL;
$lastTxID = NULL;

do {
	if ($lastTxID === NULL) {
		curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/address/$address/txs");
	} else {
		curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/address/$address/txs/chain/" . $lastTxID);
	}
	$result = curl_exec($ch);
	$txList = json_decode($result);

	foreach ($txList as $transaction) {
		$lastTxID = $transaction->txid;

		// We only care about deposits; check the transaction outputs to see if one went to this address
		foreach ($transaction->vout as $output) {
			if (isset($output->scriptpubkey_address) && $output->scriptpubkey_address == $address) {
				$oldestDepositTxId = $lastTxID;
			}
		}
	}
} while (count($txList) == 25); // there are more pages of transactions to pull

echo "\nFirst deposit transaction: $oldestDepositTxId\n";