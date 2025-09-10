<?php

// This script takes a list of bitcoin node addresses and checks them with bitnodes
// since bitnodes throttles 50 API calls per day per IP, only send a 50 address chunk
if (count($argv) < 2 || !$argv[1]) {
    echo "ERROR: first argument must be path to node address file\n";
    exit;
}

if (count($argv) < 3 || !$argv[2]) {
    echo "ERROR: second argument must be numeric page of data to send\n";
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://bitnodes.io/api/v1/checknode/");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
$headers = [
  'accept: */*',
  'accept-language: en-US,en;q=0.9',
  'content-type: application/x-www-form-urlencoded; charset=UTF-8',
  'dnt: 1',
  'origin: https://bitnodes.io',
  'priority: u=1, i',
  'referer: https://bitnodes.io/',
  'sec-ch-ua: "Not;A=Brand";v="99", Chromium";v="139"',
  'sec-ch-ua-mobile: ?0',
  'sec-ch-ua-platform: "Linux"',
  'sec-fetch-dest: empty',
  'sec-fetch-mode: cors',
  'sec-fetch-site: same-origin',
  'sec-gpc: 1',
  'user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
  'x-requested-with: XMLHttpRequest'
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$startCount = ($argv[2] - 1) * 50;
$endCount = ($argv[2]) * 50;
if (($handle = fopen($argv[1], "r")) !== FALSE) {
  $count = 0;
  while (($address = fgets($handle)) !== FALSE) {
    if ($count < $startCount) {
      continue;
    }
    if ($count >= $endCount) {
      break;
    }

    $data = array(
        'address' => $address,
        'port' => '8333'
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $result = curl_exec($ch);
    sleep(10);
  }
}