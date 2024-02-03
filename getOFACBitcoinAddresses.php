<?php

// This script parses the OFAC SDN CSV and extracts all bitcoin addresses as a list

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.treasury.gov/ofac/downloads/sdn.csv");
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$addresses = array();

$result = curl_exec($ch);

// Look for instances of: XBT <address>;
preg_match_all('/XBT \w+/', $result, $matches);

foreach ($matches[0] as $match) {
	$addresses[] = substr($match, 4, -1);
}

// Sort and print the results
sort($addresses);
foreach ($addresses as $address) {
	echo $address . "\n";
}