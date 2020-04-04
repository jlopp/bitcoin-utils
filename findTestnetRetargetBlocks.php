<?php

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:pass@localhost:18332/');

$nextBlockHash = '000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943';
$height = 0;

while ($height < 1694000) {
	$block = $bitcoind->getBlock($nextBlockHash);
	$difficulty = $block->get('difficulty');

	// If this block was mined at a difficulty of 1 it's a difficulty retarget calculation height,
	// the difficulty will reset to 1 thanks to the special testnet difficulty rule
	if ($difficulty == 1 && $height % 2016 == 2015) {
		echo $height . "\n";
	}

	$height++;
	$nextBlockHash = $block->get('nextblockhash');
}
