<?php

// This script takes the most recent reachable Bitcoin node list from bitnodes
// and then iterates through each IPV4 node and attempts to connect to it

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://bitnodes.io/api/v1/snapshots/");
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

$result = curl_exec($ch);
$snapshotListJson = json_decode($result);
$snapshotURL = $snapshotListJson->results[0]->url;
curl_setopt($ch, CURLOPT_URL, $snapshotURL);

$result = curl_exec($ch);
$snapshotJson = json_decode($result);

$totalNodes = $attemptedNodes = $reachableNodes = $unreachableNodes = 0;

foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
	$totalNodes++;
}
$percentOfNodes = floor($totalNodes / 100);

foreach ($snapshotJson->nodes as $nodeIP => $attributes) {
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

	echo "connecting to $nodeIP\n";

	// this is an IPV4 peer; attempt to connect
	$success = connectPeer(explode(":", $nodeIP)[0], explode(":", $nodeIP)[1]);
	if ($success) {
		$reachableNodes++;
	} else {
		$unreachableNodes++;
	}
}

echo "Reachable nodes: $reachableNodes\n";
echo "Unreachable nodes: $unreachableNodes\n";

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

function makeGetDataPayload($block_hash) {

    $data_array = [ // hexadecimal, network byte order
        'count'       => $version,        // 4 bytes (60002)
        'inventory'  => $start_height    // 4 bytes
    ];

    $data_payload = str_replace(' ', '', implode($data_array));
    //echo 'Version Payload: '; print_r($version_array);

    return $data_payload;
}

// Print socket error function
function error() {
    $error = socket_strerror(socket_last_error());
    return $error.PHP_EOL;
}

function connectPeer($peer_ip, $peer_port) {
	$version    = 60002;
	$local_ip = '127.0.0.1';
	$local_port = 8333;

	// create Version Message (needs to be sent to node you want to connect to)
	$payload = makeVersionPayload($version, $peer_ip, $peer_port, $local_ip, $local_port);
	$message = makeMessage("version", $payload);
	$message_size = strlen($message) / 2; // the size of the message (in bytes) being sent

	// connect to socket and send version message
	$socket = socket_create(AF_INET, SOCK_STREAM, 6); // IPv4, TCP uses this type, TCP protocol
	$success = socket_connect($socket, $peer_ip, $peer_port);
	if (!$success) {
		return false;
	}
	echo "sending data\n";
	socket_send($socket, hex2bin($message), $message_size, 0); // don't forget to send message in binary
	echo "reading data\n";
	$data = socket_read($socket, 1024);
	print_r(bin2hex($data)); echo "\n";
	if ($data === false || empty($data)) {
		return false;
	}

	// request 10 full blocks and time how long it takes to receive them
	return true;
}