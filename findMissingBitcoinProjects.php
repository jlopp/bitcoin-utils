<?php

// This script uses Electric Capitals list of Bitcoin / Lightning repos
// to find github projects that are not linked from lopp.net

// install via: composer require yosymfony/toml
require 'vendor/autoload.php';
use Yosymfony\Toml\Toml;

$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

// read-only access token to bypass rate limits
const GITHUB_API_TOKEN = '';
const GITHUB_USERNAME = '';
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Content-Type: application/json',
  'Authorization: Bearer ' . GITHUB_API_TOKEN,
  'User-Agent: ' . GITHUB_USERNAME,
  'X-GitHub-Api-Version: 2022-11-28'
));

// determine where to stop looking for new difficulty epochs
if ($argv[1] == 'bitcoin') {
	curl_setopt($ch, CURLOPT_URL, "https://raw.githubusercontent.com/electric-capital/crypto-ecosystems/master/data/ecosystems/b/bitcoin.toml");
} else if ($argv[1] == 'lightning') {
	curl_setopt($ch, CURLOPT_URL, "https://raw.githubusercontent.com/electric-capital/crypto-ecosystems/master/data/ecosystems/l/lightning.toml");
} else {
	echo "ERROR: first argument must be 'bitcoin' or 'lightning'\n";
	exit;
}

$repositories = Toml::Parse(curl_exec($ch));
$orgs = array();
$singleRepos = $activeRepos = array();
$resourceURLs = array();

// build map of org repos to skip
foreach ($repositories['github_organizations'] as $org) {
	$orgs[$org] = true;
}

// iterate through URLs and remove those belonging to oranizations
foreach ($repositories["repo"] as $repo) {
	if (!str_starts_with($repo['url'], "http")) {
		continue;
	}

	// extract org name prefix from repo URL (everything up to 4th slash)
	$orgName = split2($repo['url'], '/', 4);
	if (array_key_exists($orgName[0], $orgs)) {
		continue;
	}
	$singleRepos[] = $repo['url'];
}

// check the remaining repos and remove those that have no had any commit activity in 2+ years
foreach ($singleRepos as $repo) {
	// extract username and repo name
	$parts = explode('/', $repo);
	curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/" . $parts[3] . "/" . $parts[4] . "/commits");
	$response = json_decode(curl_exec($ch));

	// repo is private or deleted; ignore
	if (isset($response->message)) {
		echo $parts[3] . "/" . $parts[4] . " was " . $response->message . "\n";
		continue;
	}
	$lastCommitDate = strtotime($response[0]->commit->author->date);
	$yearsAgo = strtotime('-2 year');
	if ($lastCommitDate < $yearsAgo) {
		//echo $lastCommitDate . " is older than " . $yearsAgo . "\n";
	} else {
		//echo $lastCommitDate . " is younger than " . $yearsAgo . "\n";
		$activeRepos[] = $repo;	
	}
}

// extract all href URLs from bitcoin & lightning resources HTML pages
$localHTML = array('../lopp.net/lightning-information.html');
$files = scandir('../lopp.net/bitcoin-information/');
foreach ($files as $file) {
	if (str_ends_with($file, ".html")) {
		$localHTML[] = '../lopp.net/bitcoin-information/' . $file;
	}
}

foreach ($localHTML as $html) {
	$urlContent = file_get_contents($html);

	$dom = new DOMDocument();
	@$dom->loadHTML($urlContent);
	$xpath = new DOMXPath($dom);
	$hrefs = $xpath->evaluate("/html/body//a");

	for($i = 0; $i < $hrefs->length; $i++){
	    $href = $hrefs->item($i);
	    $url = $href->getAttribute('href');
	    $url = filter_var($url, FILTER_SANITIZE_URL);
	    // validate url
	    if(!filter_var($url, FILTER_VALIDATE_URL) === false){
	        $resourceURLs[$url] = true;
	    }
	}
}

// take the final repos that are left and figure out which ones aren't listed on lopp.net
foreach ($activeRepos as $repo) {
	if (!array_key_exists($repo, $resourceURLs)) {
		echo $repo . "\n";
	}
}

// used to find org name of a github URL
function split2($string, $needle, $nth) {
    $max = strlen($string);
    $n = 0;
    for ($i=0; $i<$max; $i++) {
        if ($string[$i] == $needle) {
            $n++;
            if ($n >= $nth) {
                break;
            }
        }
    }
    $arr[] = substr($string, 0, $i);
    $arr[] = substr($string, $i+1, $max);

    return $arr;
}