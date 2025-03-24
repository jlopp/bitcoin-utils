<?php

$blocks = array();

if (($handle = fopen("missingMempoolTxns.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $blocks[$data[0]] = array();
        $blocks[$data[0]]['height'] = $data[0];
        $blocks[$data[0]]['unseen'] = $data[1];
    }
    fclose($handle);
} else {
	echo "ERROR: Failed to open first file\n";
}

if (($handle = fopen('../bitcoin-blocks-by-mining-pool/blocks.csv', "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (array_key_exists($data[0], $blocks)) {
            $blocks[$data[0]]['pool'] = $data[2];
        }
    }
    fclose($handle);
} else {
    echo "ERROR: Failed to open second file\n";
}

ksort($blocks);
echo "Height,UnseenTxns,Pool\n";
foreach ($blocks as $block) {
    echo $block['height'] . ',' . $block['unseen'] . ',' . $block['pool'] . "\n";
}