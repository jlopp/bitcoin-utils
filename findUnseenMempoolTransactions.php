<?php

// This script reads block audit data from mempool.space and dumps out metrics on blocks
// containing transactions that have never been seen before showing up in the block

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$currentBlockHeight = 855420; // first block mempool.space started tracking unseen transactions
$maxHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$currentBlockHash = $bitcoind->getBlockhash($currentBlockHeight)->get();
$blockPools = array();

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

echo "height,hash,pool,unseenTxnCount,nonstandardCount,inscriptionCount\n";

while ($currentBlockHeight < $maxHeight) {
	curl_setopt($ch, CURLOPT_URL, "https://mempool.space/api/v1/block/$currentBlockHash/audit-summary");

	$result = curl_exec($ch);
	$auditSummary = json_decode($result);
	if (!empty($auditSummary->unseenTxs)) {
		$nonStandardCount = $opreturnCount = $inscriptionCount = 0;

		// get the pool that mined the block
		if (!array_key_exists($currentBlockHeight, $blockPools)) {
			curl_setopt($ch, CURLOPT_URL, "https://mempool.space/api/v1/blocks/$currentBlockHeight");

			$result = curl_exec($ch);
			$resultJson = json_decode($result);

			foreach ($resultJson as $block) {
				$blockPools[$block->height] = $block->extras->pool->slug;
			}
		}

		// get the tags for each unseen txn
		curl_setopt($ch, CURLOPT_URL, "https://mempool.space/api/v1/block/$currentBlockHash/summary");
		$result = curl_exec($ch);
		$resultJson = json_decode($result);
		foreach ($resultJson as $txn) {
			if (in_array($txn->txid, $auditSummary->unseenTxs)) {
				// mempool goggles bitfields documented at https://github.com/mempool/mempool/blob/485a58e45321d3c4f57b870811ce3e2b26be5f8f/frontend/src/app/shared/filters.utils.ts#L22-L59
				$binaryFlags = (string)decbin($txn->flags);
				if ($binaryFlags[-6] == "1") { // non-standard
					$nonStandardCount++;
				}
				if (strlen($binaryFlags) >= 33 && $binaryFlags[-33] == "1") { // OP_RETURN
					$opreturnCount++;
				}
				if (strlen($binaryFlags) >= 44 && $binaryFlags[-44] == "1") { // inscription
					$inscriptionCount++;
				}
			}
		}
		echo $auditSummary->height . "," .
			$auditSummary->id . "," .
			$blockPools[$currentBlockHeight] . "," .
			count($auditSummary->unseenTxs) . "," .
			$nonStandardCount . "," .
			$opreturnCount . "," .
			$inscriptionCount . "," .
			implode(",", $auditSummary->unseenTxs) . "\n";
	}
	$currentBlockHeight++;
	$currentBlockHash = $bitcoind->getBlockhash($currentBlockHeight)->get();
}