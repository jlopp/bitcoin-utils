<?php

// This script reads from proprietary JSON files containing lists of transaction hashes that were
// confirmed in blocks but not seen in the mempool
require 'vendor/autoload.php';

use Denpa\Bitcoin\Client as BitcoinClient;

$bitcoind = new BitcoinClient('http://username:password@localhost:8332/');
$allBlocks = array();

if (!isset($argv[1])) {
	echo "ERROR: first argument must be a path to a directory of json files!\n";
	exit;
}

if (!is_dir($argv[1])) {
	echo "ERROR: " . $argv[1] . " does not exist!\n";
	exit;
}

// Scan the directory for files
$directory = $argv[1];
$files = scandir($directory);

// read the raw data files from the given directory
foreach ($files as $file) {
    // Skip the current and parent directory entries
    if ($file == '.' || $file == '..') {
        continue;
    }

    // Construct the full path to the file
    $filePath = $directory . DIRECTORY_SEPARATOR . $file;

    // Make sure it's a file and not a directory
    if (is_file($filePath)) {
        // Open the file for reading
        $handle = fopen($filePath, "r");
        if ($handle) {
            // Read each line from the file
            while (($line = fgets($handle)) !== false) {
                // Decode the JSON from the line
                $jsonData = json_decode($line, true);

                // Check if the JSON decoding was successful
                if (json_last_error() === JSON_ERROR_NONE) {
                	processBlock($jsonData);
                } else {
                    echo "JSON decoding error on line: $line\n";
                }
            }

            // Close the file handle
            fclose($handle);
        } else {
            echo "Could not open file: $filePath\n";
        }
    }
}

function processBlock($json) {
	global $bitcoind;
	global $allBlocks;

	// get the block height for the given block hash
	$block = $bitcoind->getBlock($json["block_hash"], 1);

	// get the mining pool for the given block
	//$pool = curl();

	$missingTransactionCount = count($json["txids"]);
	$allBlocks[$block["height"]] = $missingTransactionCount;

/*
	foreach ($block->get('tx') as $transaction) {
		// Ignore coinbase transactions
		if (isset($transaction['vin'][0]['coinbase'])) {
			continue;
		}
print_r($transaction);exit;
		// check if the transaction is standard
		// Search for outputs containing OP_RETURN in the scriptPubKey asm
		foreach ($transaction['vout'] as $output) {
		    if (isset($output['scriptPubKey']['asm']) && strpos($output['scriptPubKey']['asm'], 'OP_RETURN') !== false) {
		        $has_op_return = true;
		        echo "Transaction has an OP_RETURN output: " . $output['scriptPubKey']['hex'] . "\n";
		        break;
		    }
		}
		// check if transaction is inscription
		if (count($transaction['vin'][0]->txinwitness[1]) == 3 
			&& strlen($transaction['vin'][0]->txinwitness[1]) > 500
			&& $transaction['vin'][0]->prevout->scriptPubKey->type == "witness_v1_taproot") {
			
		}
	}
	*/
}


// sort by block height ascending
ksort($allBlocks);

echo "Block Height,Missing Txns:\n";
foreach ($allBlocks as $height => $count) {
	echo "$height,$count\n";
}

// https://mempool.space/docs/api/rest#get-blocks-bulk get mining pools
// https://b10c.me/observations/09-non-standard-transactions/ notes on non-standard txns