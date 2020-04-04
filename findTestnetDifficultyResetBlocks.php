<?php

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:pass@localhost:18332/');

$nextBlockHash = '000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943';
$prevBlockTime = 1296688602;
$height = 0;

while ($height < 1694000) {
	$block = $bitcoind->getBlock($nextBlockHash);
	$difficulty = $block->get('difficulty');
	$time = $block->get('time');

	// If this block was mined at a difficulty of 1 and over 20 minutes after the previous block,
	// it's safe to assume it was mined using the special testnet difficulty rule
	if ($difficulty == 1 && $time - $prevBlockTime >= 1200) {
		echo $height . "\n";
	}

	$prevBlockTime = $time;
	$height++;
	$nextBlockHash = $block->get('nextblockhash');
}
