<?php

// This script takes the most recent reachable Bitcoin node list from bitnodes
// and then iterates through each IPV4 node and scrapes the bandwidth stats from its individual page

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://bitnodes.io/api/v1/snapshots/");
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$result = curl_exec($ch);
$snapshotListJson = json_decode($result);
$snapshotURL = $snapshotListJson->results[0]->url;
curl_setopt($ch, CURLOPT_URL, $snapshotURL);

$result = curl_exec($ch);
$snapshotJson = json_decode($result);

$totalNodes = $attemptedNodes = 0;

foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
	$totalNodes++;
}
$percentOfNodes = floor($totalNodes / 100);

foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
	$attemptedNodes++;
	if ($attemptedNodes % $percentOfNodes == 0) {
		echo floor(($attemptedNodes / $totalNodes) * 100) . "% COMPLETE\n";
	}

	// 11 is ASN
	if ($attributes[11] == 'TOR') {
		continue;
	}

	// if node IP address includes bracket charts, it's IPV6
	if (strstr($nodeIP, '[') !== false) {
		continue;
	}

	// this is an IPV4 peer; scrape its individual stats page
	curl_setopt($ch, CURLOPT_URL, "https://bitnodes.io/nodes/" . explode(":", $nodeIP)[0] . "-" . explode(":", $nodeIP)[1] . "/");

	$result = curl_exec($ch);
	// find <h4>0.0889 Mbps</h4>
	$matches = array();
	preg_match('/<h4>[0-9.-]{1,8} Mbps<\/h4>/', $result, $matches);
	echo $matches[0] . "\n";
}

echo "\n\n";
