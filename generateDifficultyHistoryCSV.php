<?php

// This script usees the blockstream explorer API
// It iterates through ever block that was minted after a difficulty retarget height
// and if the difficulty changed compared to the previous epoch, will print it in CSV format

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$currentBlockHeight = 38304; // one epoch before the first difficulty change (speed up the script by reducing API calls)
$currentBlockHash = NULL;
$currentDifficulty = 1;

// determine where to stop looking for new difficulty epochs
curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/blocks/tip/height");
$chainTipHeight = curl_exec($ch);
$maxBlockHeight = $chainTipHeight - ($chainTipHeight % 2016) - 2016;

echo "\nBlock Height,Date,Difficulty\n";
echo "0,2009-01-03,1\n";

while ($currentBlockHeight < $maxBlockHeight) {  // there are more difficulty retargets
	$currentBlockHeight += 2016;
	curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/block-height/$currentBlockHeight");
	$currentBlockHash = curl_exec($ch);

	curl_setopt($ch, CURLOPT_URL, "https://blockstream.info/api/block/$currentBlockHash");
	$block = json_decode(curl_exec($ch));
	if ($block->difficulty != $currentDifficulty) {
		echo $block->height . "," . date("Y-m-d", $block->timestamp) . "," . $block->difficulty . "\n";
	}

}