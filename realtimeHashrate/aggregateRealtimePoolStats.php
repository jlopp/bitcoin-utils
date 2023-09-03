<?php
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

// This script reads in the TSV dump of historical realtime pool hashrate metrics
// collected by braiins and aggregates them into buckets matching block heights
// so that they can be compared with blockchain-based hashrate estimates from Bitcoin Core

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to TSV hashrate file\n";
    exit;
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
    echo "ERROR: failed to open TSV hashrate file\n";
    exit;
}

$bitcoind = new BitcoinClient('http://user:pass@localhost:8332/');
$startHeight = $height = 713762; // start height of realtime data
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
// initialize array of arrays
$poolHashByBlockHeight = array(); // [blockHeight][pool] => hashrate
for ($i = $startHeight; $i < $maxBlockHeight; $i++) {
	$poolHashByBlockHeight[$i] = array();
}

// keep track of the last block height for which a realtime hashrate was bucketed
// this will come in handy for our second pass across the data
$lastBlockHeightSeen = array();

// for each block, find all of the unique reported realtime pool hash rates
// that were collected most recently before that block's timestamp
// note that, on occasion, a block's timestamp may be before the parent block's timestamp
// and thus we should use the same set of realtime reported hashrates
while ($height < $maxBlockHeight) {
	$currentBlock = $bitcoind->getBlockHeader($currentBlockHash);
	$currentBlockTime = $currentBlock->get('time');

	// find most recent realtime hashrates
	// TSV file format: Row Number,Epoch Time (Seconds),Pool Name,Pool Hashrate (EH/s)
	while (true) {
		$data = fgetcsv($handle, 1000, "\t");
		if ($data === FALSE) { // end of file
			break 2;
		}
		if ($data[3] == "\N") { // no data for this line
			continue;
		}

		if ($data[1] <= $currentBlockTime) {
			$poolHashByBlockHeight[$height][$data[2]] = $data[3];
			$lastBlockHeightSeen[$data[2]] = $height;
		} else { // we've moved on to the next block bucket
			$poolHashByBlockHeight[$height + 1][$data[2]] = $data[3];
			$lastBlockHeightSeen[$data[2]] = $height + 1;
			$height++;
			break;
		}
	}
	$currentBlockHash = $currentBlock->get('nextblockhash');
}

// Now we have bucketed all of the realtime hashrate metrics,
// however there are gaps in the buckets because the realtime
// metrics were not collected at block-level time granularity
// thus we need to fill in the gaps by populating each data point
// forward into future buckets until we hit a freshly collected metric
foreach ($poolHashByBlockHeight as $blockHeight => $pools) {
	// no more gaps to fill; prevent bug trying to access nonexistent array
	if ($blockHeight >= $maxBlockHeight) {
		break;
	}

	foreach ($pools as $pool => $hashrate) {
		$tmpBlockHeight = $blockHeight + 1;
		while (!array_key_exists($pool, $poolHashByBlockHeight[$tmpBlockHeight])) {
			if ($tmpBlockHeight >= $lastBlockHeightSeen[$pool])
				break;
			$poolHashByBlockHeight[$tmpBlockHeight][$pool] = $hashrate;
			$tmpBlockHeight++;
		}
	}
}

// output CSV file format
echo "Block Height,Aggregate Realtime Hashrate\n";
foreach ($poolHashByBlockHeight as $blockHeight => $pools) {
	$totalHashrate = 0;
	foreach ($pools as $pool => $hashrate) {
		$totalHashrate += $hashrate;
	}
	echo "$blockHeight,$totalHashrate\n";
}