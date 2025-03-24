<?php

// takes in a list of address poison transaction IDs, extracts the target victim address, finds details of target address
require '../vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:pass@localhost:8332/');

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to poison transactions file\n";
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
$processed = 0;

if (($handle = fopen($argv[1], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
    	$txid = $data[1];
    	$transaction = $bitcoind->getRawTransaction($txid, 2)->get();
    	// extract the target victim address
    	$address = $transaction['vout'][0]['scriptPubKey']['address'];
    	echo $data[0] . ',' . $address . "\n";
	}
}
