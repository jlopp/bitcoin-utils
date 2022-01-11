<?php

// This script scrapes the #bitcoin-core-dev IRC log archive hosted by chaincode and
// counts how many messages were posted each day / month

$begin = new DateTime('2019-01-01');
$end = new DateTime('2022-01-01');

$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($begin, $interval, $end);

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$counts = array();

foreach ($period as $date) {
	curl_setopt($ch, CURLOPT_URL, "https://bitcoin-irc.chaincode.com/bitcoin-core-dev/" . $date->format("Y-m-d"));
	$result = curl_exec($ch);
	$dayCount = substr_count($result, "talk op-msg");

	if (isset($counts[$date->format("Y-m")])) {
		$counts[$date->format("Y-m")] += $dayCount;
	} else {
		$counts[$date->format("Y-m")] = $dayCount;
	}
}


// Sort and print the results
$total = 0;
ksort($counts);
foreach ($counts as $month => $count) {
	echo "$month,$count\n";
}