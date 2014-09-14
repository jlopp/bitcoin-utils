<?php

require('bitcoin_client.php');

// add the RPC connection information for each node in your pool
// security note: the authentication credentials will be sent in plaintext
// unless you set up SSL certificates on the nodes and enable HTTPS on the Bitcoin client
$config = array(
                array(
                        'ip'        => '127.0.0.1',
                        'port'      => '8332',
                        'username'  => 'RPCUserHere',
                        'password'  => 'passwordHere'
                    ),
                array(
                        'ip'        => '192.168.0.1',
                        'port'      => '8332',
                        'username'  => 'RPCUserHere',
                        'password'  => 'passwordHere'
                    ),
                );

// don't allow multiple instances of this script to run simultaneously
const PID_FILE = '/tmp/nodemanager.pid';

if (file_exists(PID_FILE)) {
	$pid = file_get_contents(PID_FILE);
	if (file_exists("/proc/$pid")) {
		error_log('An instance of the node manager script is already running.');
		exit(1);
	} else {
		error_log('Previous process exited without cleaning pidfile.');
		unlink(PID_FILE);
	}
}
$h = fopen(PID_FILE, 'w');
if ($h) {
	fwrite($h, getmypid());
}
fclose($h);

// build node IP whitelist from our pool of nodes
$nodes = array();
$whitelist = array();
foreach ($config as $index => $node) {
    $nodes[] = new Bitcoin($node['username'], $node['password'], $node['ip'], $node['port']);
    //$nodes[$index]->setSSL($certificate); optionally enable SSL here
    $whitelist[$node['ip'] . ':8333'] = TRUE;
}

// query each node to check that it's online
foreach ($nodes as $index => $node) {
    if (FALSE === $node->getinfo()) {
    	// node is offline; remove from list of nodes
	    unset($nodes[$index]);
    }
}

// query each node for all of its peers
$connectedPeers = array();
foreach ($nodes as $index => $node) {
    if (FALSE === $node->getpeerinfo()) {
        echo "ERROR getting peer info from node $index\n";
        echo "$node->error\n";
        continue;
    }

    // if the node is connected to a peer to which another of our nodes is already connected,
    // instruct this node to disconnect from the peer
    $whitelistPeers = array();
    foreach ($node->response['result'] as $peer) {
        if (array_key_exists($peer['addr'], $whitelist)) {
            $whitelistPeers[$peer['addr']] = TRUE;
            continue;
        }
        
        if (array_key_exists($peer['addr'], $connectedPeers)) {
            echo "Removing duplicate peer " . $peer['addr'] . "\n";
            if (FALSE === $node->addnode($peer['addr'], 'disconnect')) {
                echo "ERROR instructing node $index to disconnect peer " . $peer['addr'] . "\n";
                echo "$node->error\n";
            }
            if (FALSE === $node->addnode($peer['addr'], 'remove')) {
                //echo "ERROR instructing node $index to remove peer " . $peer['addr'] . "\n";
                //echo "$node->error\n";
            }
        } else {
            $connectedPeers[$peer['addr']] = TRUE;
        }
    }

    // ensure that we are connected to all of the other nodes in our pool
    foreach ($whitelist as $ip => $value) {
        // don't try to connect a node to itself
        if ($ip == $config[$index]['ip'] . ':8333') {
            continue;
        }

        // don't instruct the node to connect to a whitelisted peer if it's already connected
        if (isset($whitelistPeers[$ip])) {
            continue;
        }

        echo "Instructing node $index to connect to whitelist peer $ip\n";
        if (FALSE === $node->addnode($ip, 'onetry')) {
            echo "ERROR instructing node $index to connect to $ip\n";
            echo "$node->error\n";
        }
        if (FALSE === $node->addnode($ip, 'add')) {
            //echo "ERROR instructing node $index to add $ip\n";
            //echo "$node->error\n";
        }
    }
}

unlink(PID_FILE);
