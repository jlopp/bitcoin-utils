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
		// Skip known non-mining deposits
		if ($transaction->txid == "a800944d821e6fcc70925297e0ab8b0fa056b3870ff2ed8bf018a66dd3ad8559"
			|| $transaction->txid == "7ac74d0e03cdcb8d71809a592212ba081416673041662bc538c49eb051e1d8a4"
			|| $transaction->txid == "d4dfbda660da3a12086aa8f77b93f439ec6852bde4b8712a776cebf2eab2e2e8"
			|| $transaction->txid == "0074f6ef948ef7d22421dd956c19ff4fcbe427de9ac3823af87264cd0bd5ca50"
			|| $transaction->txid == "c7d2f831767b4b28151fc51bc636a51d7ba0accc063e598c9ac1f5942f2de17e"
			|| $transaction->txid == "e7fcd68e4a014b354a737d24c23c1cfecdd458ac8d38918cf7b145f0a52ea4ee"
			|| $transaction->txid == "5302a655ec11d0e47e020a8d02e417bac7eda2f349c458a12caa3a6bff59298c"
			|| $transaction->txid == "b56447550f4df0fea169648c61bb3c2e3763c008883c7c47b036d647c886d360"
			|| $transaction->txid == "3c4e1345f0cb5e6e73314b0c233db54b4397983956df89abf0429122d79ce4c7"
			|| $transaction->txid == "8b0cf6bb862b60245030abf3ef35e9b38425b56f96a5f666f60ee4de0c7912a1"
			|| $transaction->txid == "e9d2f91c6434366525d2211620f3c6064083b6f8f9ff6f6b81eff6d8598410fa"
			|| $transaction->txid == "3dbba55165fa84dbdff834e42ffd795c516235ff1e5e942290d6af47d7020e0c"
			|| $transaction->txid == "f45b0379c52a74dcdad8eefbceae5ec801fb2b268f0425ebf1fd03043ba2bdee") {
			continue;
		}

		// We only care about deposits; check the transaction outputs to see if one went to this address
		foreach ($transaction->vout as $output) {
			if ($output->scriptpubkey_address == $address) {
				$date = gmdate("m-d-Y", $transaction->status->block_time);
				echo $date . " " . $transaction->txid . " " . $output->value . "\n";
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
$total = 0;
ksort($deposits);
foreach ($deposits as $date => $amount) {
	$total += $amount;
	echo "$date,$amount\n";
}

echo "\nTotal: $total\n";