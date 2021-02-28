<?php

require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$startHeight = $height = 0; // start height
$maxBlockHeight = $bitcoind->getBlockchaininfo()->get('blocks');
$heightRange = $maxBlockHeight - $height;
$spamBlockHeights = array();
$spamBlockValues = array();
$spamBlockFees = array();
$spamBlockSize = array();

// initialize start block vars
$currentBlockHash = $bitcoind->getBlockhash($height)->get();
$block = $bitcoind->getBlock($currentBlockHash, 2);
$height++;

function set_precision($value) {
	return sprintf('%.8F',$value);
}

while ($block->has('nextblockhash')) {
	$block = $bitcoind->getBlock($block->get('nextblockhash'), 2);

	foreach ($block->get('tx') as $transaction) {
		// Ignore transactions that don't have a lot of outputs
		if (count($transaction['vout']) < 50) {
			continue;
		}

		// Ignore likely coinjoins
		if (count($transaction['vin']) > 50) {
			continue;
		}

		// Ignore coinbase transactions
		if (isset($transaction['vin'][0]['coinbase'])) {
			continue;
		}

		// Search for outputs to a specific address
		//if (isset($transaction['vout'][0]['scriptPubKey']['addresses']) && $transaction['vout'][0]['scriptPubKey']['addresses'][0] != "1Lets1xxxx1use1xxxxxxxxxxxy2EaMkJ") {
		//	continue;
		//}

		// If a ton of the outputs have the same value, it's probably dust limit spam
		$values = array();
		foreach ($transaction['vout'] as $output) {
			$formatted = set_precision(floatval($output['value']));
			if (isset($values[$formatted])) {
				$values[$formatted]++;
			} else {
				$values[$formatted] = 1;
			}
		}

		// Determine if the transaction had dust outputs and, if so, log info
		foreach ($values as $value => $count) {
			if ($count < 50) {
				continue;
			}

			// search for a specific spam attack with this output value
			//if ($value != 0.00000001) {
			//	continue;
			//}

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

			// Calculate transaction fee paid (aggregate output value - aggregate input value)
			$inputValue = $outputValue = 0;
			foreach ($transaction['vin'] as $input) {
				$inputTx = $bitcoind->getRawtransaction($input['txid'], true)->get();
				$inputValue += set_precision(floatval($inputTx['vout'][$input['vout']]['value']));
			}
			foreach ($transaction['vout'] as $output) {
				$outputValue += set_precision(floatval($output['value']));
			}
			$fee = $outputValue - $inputValue;
			if (isset($spamBlockFees[$height])) {
				$spamBlockFees[$height] += $fee;
			} else {
				$spamBlockFees[$height] = $fee;
			}

			// Calculate total spammy output values
			foreach ($values as $v => $c) {
				if ($c < 50) {
					continue;
				}

				if ($v > 0.0001) {
					continue;
				}

				if (isset($spamBlockValues[$height])) {
					$spamBlockValues[$height] += $value * $count;
				} else {
					$spamBlockValues[$height] = $value * $count;
				}
			}

			// No need to keep iterating through output values
			break;
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
