<?php

// This script reads a bunch of tx ids from a local file and queries the blockstream explorer API
// to see if they exist. If a tx id doesn't exist, it prints the id

// get tokens at https://www.lopp.net/tweetbot/register_app.php
$readLines = explode("\n", file_get_contents("transactions.txt"));
foreach ($readLines as $txid) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/tx/" . $txid);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

	$result = curl_exec($ch);
	if ($result == "Transaction not found") {
		echo "$txid\n";
	}
}