<?php

// This script takes a bitcoin address as its first argument and pulls the deposit history from the blockstream explorer API
// It then outputs a CSV of deposit amounts by date

$address = $argv[1];
if (empty($address)) {
	echo "ERROR: first argument must be bitcoin address\n";
	exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$deposits = array();
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
			if ($output->scriptpubkey_address == $address) {
				$date = gmdate("m-d-Y", $transaction->status->block_time);
				//if ($transaction->status->block_height > 565151 && $transaction->status->block_height < 569683) {
				//	echo $transaction->txid . " " . $output->value . "\n";
				//}
				if (isset($deposits[$date])) {
					$deposits[$date] += $output->value;
				} else {
					$deposits[$date] = $output->value;
				}
				break;
			}
		}
	}
} while (count($txList) == 25); // there are more pages of transactions to pull

// Sort and print the results
ksort($deposits);
foreach ($deposits as $date => $amount) {
	echo "$date,$amount\n";
}