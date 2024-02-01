<?php

// This script takes the full useragent snapshot history from bitnodes
// ex: https://bitnodes.io/static/txt/epoch-to-user-agents-count-1402099200-1705104000.json
// and then tracks the lifecycle of each useragent from first seen to last seen
// in order to determine how long a given release version is generally in use

// check to see if we have JSON file
if (isset($argv[1]) && is_numeric($argv[1])) {
	if (!file_exists($argv[1])) {
		echo "ERROR: " . $argv[1] . " does not exist!\n";
	}
}

$userAgentLongevity = array();

// load the snapshot json and prune it down to chunk N based on the argument
$snapshots = json_decode(file_get_contents($argv[1]));
foreach ($snapshots as $timestamp => $userAgents) {
	// history starts at June 7 2014; Bitcoin Core 0.9 was released March 2014 and 0.10 was released Feb 2015
	// as such, only start tracking user agent history starting with Bitcoin Core 0.10
	if ($timestamp < 1418428800) {
		continue;
	}

	foreach ($userAgents as $userAgent => $count) {
		// Only count Bitcoin Core useragents
		if (!str_starts_with($userAgent, 'Satoshi:')) {
			continue;
		}

		// Only count useragents with 10+ nodes
		if ($count < 10) {
			continue;
		}

		// Only store Bitcoin Core Version
		$userAgent = substr($userAgent, 8);

		// remove versions < 0.10 because we don't have their full lifecycle history
		if (version_compare($userAgent, '0.10') == -1) {
			continue;
		}

		// ignore versions ending in ".99" because those are master branch builds
		if (str_ends_with($userAgent, '.99') || str_ends_with($userAgent, '.99.0')) {
			continue;
		}

		// ignore versions > 1.0 and < 20.0 as those aren't Bitcoin Core releases
		if (version_compare($userAgent, '1.0.0') >= 0 && version_compare($userAgent, '20.0') < 0) {
			continue;
		}

		// bucket this user agent's count based upon how many snapshots (weeks) it has been since first seen
		if (isset($userAgentLongevity[$userAgent])) {
			array_push($userAgentLongevity[$userAgent], array($timestamp, $count));
		} else {
			$userAgentLongevity[$userAgent] = array(array($timestamp, $count));
		}
	}
}

// print a CSV we can use to draw some charts
echo "Week," . implode(',', array_keys($userAgentLongevity)) . "\n";
for ($week = 0; $week < 200; $week++) {
	echo "$week,";
	foreach ($userAgentLongevity as $snapshot) {
		if (isset($snapshot[$week])) {
			echo $snapshot[$week][1];
		} else {
			echo "0";
		}
		echo ",";
	}
	echo "\n";
}

// find peak node count for each user agent and determine
// how many weeks it took for 95% of those nodes to upgrade versions
foreach ($userAgentLongevity as $version => $snapshots) {

	$maxCount = 0;
	$maxWeek = 0;
	$totalWeeks = 0;
	foreach ($snapshots as $week => $snapshot) {
		if ($snapshot[1] > $maxCount) {
			$maxCount = $snapshot[1];
			$maxWeek = $week;
		}
	}

	// find the first week at which count is <= 5% of $maxCount
	foreach ($snapshots as $week => $snapshot) {
		if ($week < $maxWeek) {
			continue;
		}
		if ($snapshot[1] <= $maxCount*0.05) {
			$totalWeeks = $week - $maxWeek;
		}
	}

	echo "$version, $totalWeeks\n";
}