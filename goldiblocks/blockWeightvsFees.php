<?php

// This script 

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:password@localhost:8332/');

$startHeight = $height = 799999;
$maxBlockHeight = 890000;
$stats = array();

function getSubsidy(int $height): float {
    $initialReward = 50.0;          // Initial block reward in BTC
    $halvingInterval = 210000;      // Blocks per halving

    $halvings = intdiv($height, $halvingInterval);

    // After 64 halvings, the reward is effectively 0 due to precision limits
    if ($halvings >= 64) {
        return 0.0;
    }

    return $initialReward / pow(2, $halvings);
}

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlockheader($currentBlockHash);
$nextBlockHash = $block->get('nextblockhash');

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlock($nextBlockHash);
	$weight = $block["weight"];
	$coinbase = $bitcoind->getRawtransaction($block["tx"][0], true)->get();

	$fees = 0;
	foreach ($coinbase['vout'] as $output) {
		$fees += $output['value'];
	}

	$fees -= getSubsidy($height);
	$fees *= 100_000_000;

	$stats[$height] = array("fees" => $fees, "weight" => $weight);
	$height++;
	$nextBlockHash = $block->get('nextblockhash');
}

// aggregate stats into 1,000 block periods
for ($periodStartHeight = $startHeight; $periodStartHeight < $maxBlockHeight; $periodStartHeight += 1000) {
	$totalWeight = 0;
	$totalFees = 0;

	for ($height = $periodStartHeight; $height < $periodStartHeight + 1000; $height++) {
		$totalWeight += $stats[$height]["weight"];
		$totalFees += $stats[$height]["fees"];
	}

	$feePerWU = round($totalFees / $totalWeight);
	echo "$periodStartHeight,$totalWeight,$totalFees,$feePerWU\n";
}