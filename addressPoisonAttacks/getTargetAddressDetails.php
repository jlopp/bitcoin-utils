<?php

// This script reads a CSV of target victim bitcoin 'poison' addresses and gets metrics for them

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to bitcoin address file\n";
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
$processed = 0;

if (($handle = fopen($argv[1], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    	$address = $data[1];
		curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/address/$address");
		$result = json_decode(curl_exec($ch));

		// validity check
		if (!isset($result->chain_stats) || $result->chain_stats->funded_txo_sum == 0 || $result->chain_stats->funded_txo_count == 0) {
			continue;
		}
		$avgDeposit = ($result->chain_stats->funded_txo_sum / $result->chain_stats->funded_txo_count) / 100000000;
		$avgWithdrawal = 0;
		if ($result->chain_stats->spent_txo_count > 0) {
			$avgWithdrawal = ($result->chain_stats->spent_txo_sum / $result->chain_stats->spent_txo_count) / 100000000;
		}
		echo $address . "," . $result->chain_stats->funded_txo_count . ",$avgDeposit," . $result->chain_stats->spent_txo_count . ",$avgWithdrawal,";

		// get transaction details
		curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/address/$address/txs");
		$result = json_decode(curl_exec($ch));
		try {
			if ($result == null) {
				echo " Unknown history for $address\n";
			} else {
				$newestTxn = $result[0];
				$oldestTxn = array_pop($result);

				curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/tx/" . $oldestTxn->txid);
				$result = json_decode(curl_exec($ch));
				if ($result->status == null or !isset($result->status->block_time)) {
					echo "\n";
					continue;
				}
				$date = date('Y-m-d', $result->status->block_time);
				echo "$date,";

				curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/tx/" . $newestTxn->txid);
				$result = json_decode(curl_exec($ch));
				if ($result->status == null or !isset($result->status->block_time)) {
					echo "\n";
					continue;
				}
				$date = date('Y-m-d', $result->status->block_time);
				echo "$date\n";
			}
		} catch (Exception $e) {
			echo "Error getting txn history for $address\n";
		}
	}
}
