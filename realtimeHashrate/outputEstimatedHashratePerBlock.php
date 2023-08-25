<?php
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

// This script calls the Bitcoin Core "getnetworkhashps" RPC endpoint
// for a given trailing number of blocks at each block height

if (count($argv) < 2 || !$argv[1] || $argv[1] < 1 || $argv[1] > 1000) {
    echo "ERROR: first argument must be an integer for trailing number of blocks used to estimate hashrate\n";
    exit;
}

$bitcoind = new BitcoinClient('http://qwerty:qwerty@localhost:8332/');
$startHeight = $height = 713762; // start height of realtime data
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$trailingBlocks = (int)$argv[1];

echo "Block Height,Hashrate Estimate $trailingBlocks Blocks\n";
while ($height < $maxBlockHeight) {
	$hashrate = $bitcoind->getNetworkHashPS($trailingBlocks, $height)->get();
	// store hashrate in exahashes with 2 decimals precison
	$exaHashrate = number_format($hashrate / 1E+18, 2);
	echo "$height,$exaHashrate\n";
	$height++;
}