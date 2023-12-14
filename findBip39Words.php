<?php

// This script reads in a BIP39 word list along with another text file
// and finds all the BIP39 words that exist in the text file
// BIP39 word list: https://raw.githubusercontent.com/bitcoin/bips/master/bip-0039/english.txt

if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to word list file\n";
    exit;
}

if (count($argv) < 3 || !$argv[2]) {
    echo "ERROR: second argument must be path to text file to search\n";
    exit;
}

$word_list = array();

// ingest word list
if (($handle = fopen($argv[1], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        foreach ($data as $word) {
            $word_list[$word] = 1;
        }
    }
    fclose($handle);
} else {
	echo "ERROR: Failed to open word list file\n";
}

$found_words = array();
// ingest text file, check for words
if (($handle = fopen($argv[2], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
        foreach ($data as $word) {
            $word = trim($word);
            if (array_key_exists($word, $word_list)) {
                $found_words[$word] = array();
            }
        }
    }
    fclose($handle);
} else {
    echo "ERROR: Failed to open text file\n";
}

ksort($found_words);
foreach ($found_words as $word => $one) {
    echo "$word\n";
}