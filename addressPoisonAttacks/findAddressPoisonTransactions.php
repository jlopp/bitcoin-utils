<?php

require '../vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://user:pass@localhost:8332/');
$startHeight = $height = 797569; // start height
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$heightRange = $maxBlockHeight - $height;
$spamBlockHeights = array();
$spamBlockValues = array();
$spamBlockFees = array();
$spamBlockSize = array();

// example 1 https://mempool.space/tx/7e9fc6393f24e6d574bfce8c19aac20c1be3c5171a818afb9701483cd3eb5c30
// example 2 https://mempool.space/tx/eb692afae43f8246f1b60657271d37e4a4ab183ae542aa7452fa49b2359cf839

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlock($currentBlockHash, 2);
$height++;

function set_precision($value) {
	return sprintf('%.8F',$value);
}

while ($height < $maxBlockHeight) {
	$block = $bitcoind->getBlock($block->get('nextblockhash'), 2);

	foreach ($block->get('tx') as $transaction) {
		// 1-input, 1-output transaction with low values are suspicious
		if (count($transaction['vin']) == 1 && count($transaction['vout']) == 1 && set_precision(floatval($transaction['vout'][0]['value'])) < 0.00001000) {
			// ignore taproot inscriptions
			if (isset($transaction['vin'][0]['txinwitness']) && count($transaction['vin'][0]['txinwitness']) == 3) {
				continue;
			}
			// ignore OP_RETURN outputs
			if(!isset($transaction['vout'][0]['scriptPubKey']['address'])) {
				continue;
			}

			$transaction = $bitcoind->getRawtransaction($transaction['txid'], 2)->get();

			// ignore if it's the exact same address
			if ($transaction['vin'][0]['prevout']['scriptPubKey']['address'] == $transaction['vout'][0]['scriptPubKey']['address']) {
				continue;
			}

			// is it address poisoning?
			if (isset($transaction['vin'][0]['prevout']['scriptPubKey']['address']) && isset($transaction['vout'][0]['scriptPubKey']['address']) 
				&& substr($transaction['vin'][0]['prevout']['scriptPubKey']['address'], 0, 3) == substr($transaction['vout'][0]['scriptPubKey']['address'], 0, 3)
				&& substr($transaction['vin'][0]['prevout']['scriptPubKey']['address'], -4) == substr($transaction['vout'][0]['scriptPubKey']['address'], -4)) {
					echo "$height " . $transaction['txid'] . " " . $transaction['vin'][0]['prevout']['scriptPubKey']['address'] . " " . $transaction['vout'][0]['value'] . "\n";
			} else {
				continue;
			}
		} else {
			continue;
		}

		// If we got this far, consider this transaction to be spam
		if (isset($spamBlockHeights[$height])) {
			$spamBlockHeights[$height]++;
		} else {
			$spamBlockHeights[$height] = 1;
		}

		if (isset($spamBlockSize[$height])) {
			$spamBlockSize[$height] += $transaction['vsize'];
		} else {
			$spamBlockSize[$height] = $transaction['vsize'];
		}

		if (isset($spamBlockFees[$height])) {
			$spamBlockFees[$height] += $transaction['fee'];
		} else {
			$spamBlockFees[$height] = $transaction['fee'];
		}

		// Calculate total spammy output values
		foreach ($transaction['vout'] as $vout) {
			if (isset($spamBlockValues[$height])) {
				$spamBlockValues[$height] += $vout['value'];
			} else {
				$spamBlockValues[$height] = $vout['value'];
			}
		}
	}

	if ($height % 1000 == 0) {
		$complete = round(100*(($height - $startHeight) / $heightRange),2);
		echo "$complete%\n";
	}
	$height++;
}

// sort by block height ascending
ksort($spamBlockHeights);

echo "Block Height,Spam Txns:\n";
foreach ($spamBlockHeights as $height => $count) {
	echo "$height,$count\n";
}

// sort by block height ascending
ksort($spamBlockSize);

echo "Block Height,Spam Txn Size:\n";
foreach ($spamBlockSize as $height => $size) {
	echo "$height,$size\n";
}

// sort by block height ascending
ksort($spamBlockFees);

echo "Block Height,Spam Txn Fees:\n";
foreach ($spamBlockFees as $height => $fees) {
	echo "$height," . set_precision($fees) . "\n";
}

// sort by block height ascending
ksort($spamBlockValues);

echo "Block Height,Spam Txn value:\n";
foreach ($spamBlockValues as $height => $value) {
	echo "$height,$value\n";
}
