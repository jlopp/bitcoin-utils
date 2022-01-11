<?php

// This script reads in the CSV of historical slush pool mined block data
// for a given range of block heights and calculates how much a miner with
// a given amount of hashrate would have earned if they had been mining during that period

// CSV file format: Block Height,Block Timestamp,Block Value,Pool Hashrate (GH/s)

$startingHashrate = $minerHashrate = $argv[1];
if (!is_numeric($argv[1])) {
	echo "ERROR: first argument must be miner hashrate in GH/s\n";
	exit;
}

$minHeight = $argv[2];
if (!is_numeric($argv[2])) {
	echo "ERROR: second argument must be min block height of range\n";
	exit;
}

$maxHeight = $argv[3];
if (!is_numeric($argv[3])) {
	echo "ERROR: third argument must be max block height of range\n";
	exit;
}

$future = isset($argv[4]) && $argv[4] == "future";
if ($future) {
	echo "Running estimate for future potential revenue\n";
} else {
	echo "Running algorithm for matching past revenue\n";
}

$totalRevenue = 0;
$earningsByDate = array();
$hashrateByDate = array();

echo "Block Height,Date,Expected Revenue,Projected Hashrate\n";

if (($handle = fopen("slushpoolHistory.csv", "r")) !== FALSE) {
	// Throw away the first line (column headers)
	$data = fgetcsv($handle, 1000, ",");

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $height = $data[0];
        if ($height > $maxHeight) {
        	continue;
        }
        if ($height < $minHeight) {
        	// no more blocks in range
        	break;
        }
        $blockValue = $data[2];
        $poolHashrate = $data[3];

        // If this is a forward-looking projection when user was not actually mining,
        // we must add their expected hashrate contribution to the pool's reported hashrate
        if ($future) {
        	// calculate the expected miner hashrate by factoring in depreciation due to hardware failure
        	// we model a 15% annual failure rate which equates to 1.25% hashpower loss ever 4,380 blocks if applied monthly
        	$minerHashrate = $startingHashrate * 0.9875 ** floor(($height - $minHeight) / 4380);
        	$poolHashrate += $minerHashrate;
        }

        // Expected revenue is this miner's % of the pool hashrate time the reward, minus the pool's 2% fee
        $estimatedRevenueForBlock = number_format((($minerHashrate / $poolHashrate) * $blockValue) * 0.98, 8, '.', '');
        $totalRevenue += $estimatedRevenueForBlock;
        $date = gmdate("m-d-Y",$data[1]);
        if (isset($earningsByDate[$date])) {
        	$earningsByDate[$date] += $estimatedRevenueForBlock;
        } else {
        	$earningsByDate[$date] = $estimatedRevenueForBlock;        	
        }
        $hashrateByDate[$date] = $minerHashrate;
        echo "$height,$date,$estimatedRevenueForBlock,$minerHashrate\n";
    }
} else {
	echo "ERROR: Failed to open CSV file\n";
}
fclose($handle);

echo "Date,Expected Revenue,Projected Hashrate\n";
foreach ($earningsByDate as $date => $revenue) {
	echo "$date,$revenue," . $hashrateByDate[$date] . "\n";
}
echo "Estimated total revenue for range is: $totalRevenue\n";