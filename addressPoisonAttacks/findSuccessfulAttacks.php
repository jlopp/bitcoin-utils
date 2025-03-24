<?php

// This script reads a CSV of malicious bitcoin 'poison' addresses and searches for deposits to them

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to bitcoin address file\n";
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
$processed = 0;

if (($handle = fopen($argv[1], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
    	$address = $data[0];
		//curl_setopt($ch, CURLOPT_URL, "https://mempool.space/api/address/$address/txs");
		curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/address/$address/txs");
		$result = json_decode(curl_exec($ch));
		try {
			if ($result == null) {
				echo "Unknown history for $address\n";
			} else if (count($result) > 2) {
				// sum up the total of all deposits to the attacker address
				$deposits = 0;
				foreach ($result as $txn) {
					foreach ($txn->vout as $out) {
						if (isset($out->scriptpubkey_address) && $out->scriptpubkey_address == $address) {
							$deposits += $out->value;
						}
					}
				}
				if ($deposits > 20000) {
					echo "$address,$deposits\n";
				}
			}
		} catch (Exception $e) {
			echo "Error getting txn history for $address\n";
		}
		$processed++;
		if ($processed % 1000 == 0) {
			echo "processed $processed\n";
		}
	}
}
