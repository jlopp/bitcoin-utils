<?php

// This script reads block audit data from mempool.space and dumps out metrics on blocks
// containing transactions that have never been seen before showing up in the block

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:password@localhost:8332/');
$currentBlockHeight = 855420; // first block mempool.space started tracking unseen transactions
$maxHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$blockPools = array();
$ch = curl_init();

echo "height,hash,pool,unseenTxnCount,nonstandardCount,OP_RETURN Count,inscriptionCount\n";

for (;$currentBlockHeight < $maxHeight; $currentBlockHeight++) {
	$currentBlockHash = $bitcoind->getBlockhash($currentBlockHeight)->get();

	// since different servers have different mempools, use the response with the fewest unseen txns
	$auditSummary = null;
	for ($i = 202; $i <= 206; $i++) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_URL, "https://node$i.tk7.mempool.space/api/v1/block/$currentBlockHash/audit-summary");

		$result = curl_exec($ch);
		$audit = json_decode($result);

		// get results from a bunch of other servers
		if ($result == "audit not available" || $audit == null) {
			echo "$currentBlockHeight audit unavailable\n";
			$auditSummary == null;
			break;
		}

		// if any server has 0 unseen, that's the lowest. we can skip further checks.
		if (count($audit->unseenTxs) == 0) {
			$auditSummary == null;
			break;
		}

		if ($auditSummary == null) {
			$auditSummary = $audit;
		} else if (count($audit->unseenTxs) < count($auditSummary->unseenTxs)) {
			$auditSummary = $audit;
		}
	}

	if ($auditSummary == null) {
		continue;
	}

	$nonStandardCount = $opreturnCount = $inscriptionCount = 0;

	// get the pool that mined the block
	if (!array_key_exists($currentBlockHeight, $blockPools)) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_URL, "https://mempool.space/api/v1/blocks/$currentBlockHeight");

		$result = curl_exec($ch);
		$blocksJson = json_decode($result);

		foreach ($blocksJson as $block) {
			$blockPools[$block->height] = $block->extras->pool->slug;
		}
	}

	// get the tags for each unseen txn
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
			if (strlen($binaryFlags) >= 25 && $binaryFlags[-25] == "1") { // OP_RETURN
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