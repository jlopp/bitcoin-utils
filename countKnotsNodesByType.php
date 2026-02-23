<?php

// This script takes the most recent reachable Bitcoin node list from bitnodes
// and then iterates through each node looking for knots and bucketing by network address type

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://bitnodes.io/api/v1/snapshots/latest/");
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$result = curl_exec($ch);
$snapshotJson = json_decode($result);

$ipv4 = $ipv6 = $tor = 0;

foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
	$totalNodes++;
}
$percentOfNodes = floor($totalNodes / 100);

foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
	$attemptedNodes++;
	if ($attemptedNodes % $percentOfNodes == 0) {
		echo floor(($attemptedNodes / $totalNodes) * 100) . "% COMPLETE\n";
	}

	// 1 is useragent; ignore non-knots nodes
	if (stristr($attributes[1], "knots") === FALSE) {
		continue;
	}

	// tor addresses
	if (stristr($nodeIP, 'onion') !== FALSE) {
		$tor++;
	// if node IP address includes bracket charts, it's IPV6
	} else if (strstr($nodeIP, '[') !== FALSE) {
		$ipv6++;
	} else {
		$ipv4++;
	}
}

echo "\n\nKnots nodes:\n";
echo "IPV4: $ipv4\nIPV6: $ipv6\ntor: $tor\n";