<?php

// This script takes the most recent reachable Bitcoin node list from bitnodes
// and then iterates through each IPV4 node and attempts to connect to it

global $socket;
$chunkJson = array();

// check to see if we have a list of IP addreses
if (isset($argv[1]) && is_numeric($argv[1])) {
	if (!file_exists("nodes.json")) {
		echo "ERROR: nodes.json does not exist!\n";
	}
	// load the snapshot json and prune it down to chunk N based on the argument
	$snapshotJson = json_decode(file_get_contents("nodes.json"));
	$count = 0;
	foreach ($snapshotJson->nodes as $node) {
		$count++;
	}
	$chunkSize = floor($count / 100);
	$rangeStart = $chunkSize * $argv[1];
	$rangeEnd = $chunkSize * $argv[1] + $chunkSize;

	$count = 0;
	foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
		if ($count >= $rangeStart && $count < $rangeEnd) {
			$chunkJson[$nodeIP] = $attributes;
		}
		$count++;
	}
} else {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://bitnodes.io/api/v1/snapshots/");
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

	$result = curl_exec($ch);
	$snapshotListJson = json_decode($result);
	$snapshotURL = $snapshotListJson->results[0]->url;
	curl_setopt($ch, CURLOPT_URL, $snapshotURL);

	$result = curl_exec($ch);
	file_put_contents("nodes.json", $result);
	echo "Wrote nodes snapshot json file to disk\n";
	exit;
}

$totalNodes = $attemptedNodes = $reachableNodes = $unreachableNodes = $prunedNodes = 0;
$blockDownloadFailed = $blockDownloadSucceeded = 0;
$blockDownloadTimes = array();

foreach ($chunkJson as $nodeIP => $attributes) {
	$totalNodes++;
}
$percentOfNodes = floor($totalNodes / 100);

foreach ($chunkJson as $nodeIP => $attributes) {
	$attemptedNodes++;
	if ($attemptedNodes % $percentOfNodes == 0) {
		echo floor(($attemptedNodes / $totalNodes) * 100) . "% COMPLETE\n";
	}

	// 11 is ASN
	if ($attributes[11] == 'TOR') {
		continue;
	}

	// if node IP address includes bracket charts, it's IPV6
	if (strstr($nodeIP, '[') !== false) {
		continue;
	}

	//echo "connecting to $nodeIP\n";

	// this is an IPV4 peer; attempt to connect
	$success = connectPeer(explode(":", $nodeIP)[0], explode(":", $nodeIP)[1]);
	if ($success) {
		$reachableNodes++;
	} else {
		$unreachableNodes++;
	}

	// is this a pruned node? if not, attempt to get some older blocks from it
	// look for service bits that don't include the NODE_NETWORK service flag
	if ($attributes[3] == 1032) {
		$prunedNodes++;
		continue;
	}

	$time = requestBlocks();
	if ($time === false) {
		$blockDownloadFailed++;
	} else {
		$blockDownloadSucceeded++;
		$blockDownloadTimes[] = $time;
	}
}

$output =
"Reachable nodes:$reachableNodes
Unreachable nodes:$unreachableNodes
Pruned nodes:$prunedNodes
Block Download Failed:$blockDownloadFailed
Block Download Succeeded:$blockDownloadSucceeded
Block Download Times:" . implode(",", $blockDownloadTimes) . "\n";
file_put_contents("node_bandwidth_stats_" . $argv[1] . ".csv", $output);

// ------------------
// P2P Message functions
// ------------------

function fieldsize($field, $bytes = 1) {
    $length = $bytes * 2;
    $result = str_pad($field, $length, '0', STR_PAD_LEFT);
    return $result;
}

function swapEndian($hex) {
    return implode('', array_reverse(str_split($hex, 2)));
}

function byteSpaces($bytes) { // add spaces between bytes
    $bytes = implode(' ', str_split(strtoupper($bytes), 2));
    return $bytes;
}

function timestamp($time) { // convert timestamp to network byte order
    $time = dechex($time);
    $time = fieldsize($time, 8);
    $time = swapEndian($time);
    return byteSpaces($time);
}

function networkaddress($ip, $port = '8333') { // convert ip address to network byte order
    $services = '01 00 00 00 00 00 00 00'; // 1 = NODE_NETWORK

    $ipv6_prefix = '00 00 00 00 00 00 00 00 00 00 FF FF';

    $ip = explode('.', $ip);
    $ip = array_map("dechex", $ip);
    $ip = array_map("fieldsize", $ip);
    $ip = array_map("strtoupper", $ip);
    $ip = implode(' ', $ip);

    $port = dechex($port); // for some reason this is big-endian
    $port = byteSpaces($port);

    return "$services $ipv6_prefix $ip $port";
}

function checksum($string) {
    $string = hex2bin($string);
    $hash = hash('sha256', hash('sha256', $string, true));
    $checksum = substr($hash, 0, 8);
    return byteSpaces($checksum);
}

function makeMessage($command, $payload) {

    // Header
    $magicbytes = 'F9 BE B4 D9';
	$commandHex = "";
	for ($i = 0; $i < 12; $i++) {
		if (strlen($command) > $i) {
			$commandHex .= bin2hex($command[$i]) . " ";
		} else {
			$commandHex .= "00 ";
		}
	}
    $payload_size = bytespaces(swapEndian(fieldsize(dechex(strlen($payload) / 2), 4)));
    $checksum = checksum($payload);

    $header_array = [
        'magicbytes'    => $magicbytes,
        'command'       => $commandHex,
        'payload_size'  => $payload_size,
        'checksum'      => $checksum,
    ];

    $header = str_replace(' ', '', implode($header_array));
    //echo 'Header: '; print_r($header_array);

    return $header.$payload;

}

function makeVersionPayload($version, $node_ip, $node_port, $local_ip, $local_port) {

    // settings
    $services = '01 00 00 00 00 00 00 00'; // (1 = NODE_NETORK)
    $user_agent = '00';
    $start_height = 0;

    // prepare
    $version = bytespaces(swapEndian(fieldsize(dechex($version), 4)));
    $timestamp = timestamp(time()); // 73 43 c9 57 00 00 00 00
    $recv = networkaddress($node_ip, $node_port);
    $from = networkaddress($local_ip, $local_port);
    $nonce = bytespaces(swapEndian(fieldsize(dechex(mt_rand()), 8)));
    $start_height = bytespaces(swapEndian(fieldsize(dechex($start_height), 4)));

    $version_array = [ // hexadecimal, network byte order
        'version'       => $version,        // 4 bytes (60002)
        'services'      => $services,       // 8 bytes
        'timestamp'     => $timestamp,      // 8 bytes
        'addr_recv'     => $recv,           // 26 bytes
        'addr_from'     => $from,           // 26 bytes
        'nonce'         => $nonce,          // 8 bytes
        'user_agent'    => $user_agent,     // varint
        'start_height'  => $start_height    // 4 bytes
    ];

    $version_payload = str_replace(' ', '', implode($version_array));
    //echo 'Version Payload: '; print_r($version_array);

    return $version_payload;
}


function makeGetDataPayload() {

	$block_hashes = [
		bytespaces(swapEndian(fieldsize(dechex(2), 4))), // MSG_BLOCK inventory type
		swapEndian(strtoupper("00000000000000000024fb37364cbf81fd49cc2d51c09c75c35433c3a1945d04")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000005c9959b3216f8640f94ec96edea69fe12ad7dee8b74e92")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("000000000000000000877d93d1412ca671750152ba0862db95f073b82c04b191")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000005467c7a728a3dcb17080d5fdca330043d51e298374f30e")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000005d4da5924742e6d6372745f15c39feb05cf9b2e49e646d")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000007150115460d0e92093aa937a913072d768f8136e289c2d")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("000000000000000000063a1cc4280179e0f95d05c5d6edabcfe35ecbe29ab525")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000002f6f0603757e974df0eb92db8e780d515b85d7de89bd98")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000005f3b9fdd039ce2d228258cc822c04f1916b3162eded824")),
		bytespaces(swapEndian(fieldsize(dechex(2), 4))),
		swapEndian(strtoupper("0000000000000000006bcf0cd88715eeb3b399644cb50c087781c8236fa08929"))
	];

    $data_array = [ // hexadecimal, network byte order
        'count'      => bytespaces(swapEndian(fieldsize(dechex(10), 1))),
        'inventory'  => implode($block_hashes)
    ];

    $data_payload = str_replace(' ', '', implode($data_array));
    //echo 'GetData Payload: '; print_r($version_array);

    return $data_payload;
}

function connectPeer($peer_ip, $peer_port) {
	global $socket;
	$version    = 70016;
	$local_ip = '127.0.0.1';
	$local_port = 8333;

	// create Version Message (needs to be sent to node you want to connect to)
	$payload = makeVersionPayload($version, $peer_ip, $peer_port, $local_ip, $local_port);
	$message = makeMessage("version", $payload);
	$message_size = strlen($message) / 2; // the size of the message (in bytes) being sent

	// connect to socket and send version message
	$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	$success = socket_connect($socket, $peer_ip, $peer_port);
	if (!$success) {
		return false;
	}

	$success = socket_send($socket, hex2bin($message), $message_size, 0);
	if (!$success) {
		return false;
	}

	sleep(1);
	$data = readSocketData();

	if ($data === false || empty($data)) {
		return false;
	}

	//echo "\nReceived version response: "; print_r(bin2hex($data)); echo "\n";

	// send verack response with empty payload so that we can send other messages next
	$message = makeMessage("verack", "");
	$message_size = strlen($message) / 2;
	$success = socket_send($socket, hex2bin($message), $message_size, 0);

	if (!$success) {
		return false;
	}

	// listen for "ping" message (starts with 70 69 6e 67)
	$data = readSocketData();

	if ($data === false || empty($data)) {
		return false;
	}

	//echo "\nPing data: " . bin2hex($data) . "\n";

	// send "pong" reply with nonce we received
	//$message = makeMessage("pong", bin2hex($data));
	//$message_size = strlen($message) / 2;
	//socket_send($socket, hex2bin($message), $message_size, 0);

	// listen for "getheaders" message
	$data = readSocketData();

	//if ($data === false || empty($data)) {
	//	return false;
	//}

	// send empty "headers" reply
	$message = makeMessage("headers", "");
	$message_size = strlen($message) / 2;
	$success = socket_send($socket, hex2bin($message), $message_size, 0);

	if (!$success) {
		return false;
	}

	return true;
}

// request 10 full blocks from currently connected peer
// return time in ms if we got all the expected data
// return false if we failed to get all the data
function requestBlocks() {
	global $socket;
	$payload = makeGetDataPayload();
	$message = makeMessage("getdata", $payload);
	$message_size = strlen($message) / 2;
	//echo "Sent GetData message: $message\n\n";
	$success = socket_send($socket, hex2bin($message), $message_size, 0);
	if (!$success) {
		return false;
	}

	$data = "";
	// time how long it takes to receive blocks
	$start_time = round(microtime(true) * 1000);

	// 2 minute timeout if data stops flowing
	for ($i = 0; $i < 120; $i++) {
		$newData = readSocketData();
		if (!empty($newData)) {
			$i = 0;
			$data .= $newData;
		}

		// we haven't received all the data yet, keep looping
		if (strlen($data) < 9770000) {
			sleep(1);
		} else {
			break;
		}
	}
	$end_time = round(microtime(true) * 1000);
	$total_time = $end_time - $start_time;
	//echo "Received getdata response: "; print_r(bin2hex($data)); echo "\n";
	// data received should be > 9772653 bytes
	//echo "Received getdata response of length: " . strlen($data) . " in " . $total_time . "ms\n";

	if (strlen($data) >= 9770000) {
		return $total_time;
	} else {
		return false;
	}
}

// keep reading data from socket until exhausted
function readSocketData() {
	global $socket;
	$allData = $newData = "";

	while (true) {
		// try 10 times over period of 0.1 second to read data
		for ($i = 0; $i < 10; $i++) {
			$success = socket_recv($socket, $newData, 1024, MSG_DONTWAIT);
			//$newData = socket_read($socket, 1);
			if (!empty($newData)) {
				$allData .= $newData;
				//echo bin2hex($newData);
			} else { // sleep for 1ms if we didn't read any data
				//echo " sleeping to read more data\n";
				usleep(10000);
			}
		}

		// no more data available to read
		if (empty($newData)) {
			break;
		}
		//echo "Waiting to read more data...\n";
	}

	return $allData;
}