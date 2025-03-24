<?php

$lowestValue = 999999999;
for ($i = 0; $i < 10; $i++) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

	curl_setopt($ch, CURLOPT_URL, "https://mempool.space/api/v1/block/0000000000000000000124686ed1ea14c004819e409b49e2f8b7268b55cff28d/audit-summary");
	$result = curl_exec($ch);
	$auditSummary = json_decode($result);

	$unseenTxs = count($auditSummary->unseenTxs);
	if ($unseenTxs < $lowestValue)
		$lowestValue = $unseenTxs;
}

echo "$lowestValue\n";