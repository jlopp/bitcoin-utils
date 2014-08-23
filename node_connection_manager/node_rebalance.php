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

// build node IP whitelist from our pool of nodes
// initialize connections to nodes
$nodes = array();
$whitelist = array();
foreach ($config as $index => $node) {
    $nodes[] = new Bitcoin($node['username'], $node['password'], $node['ip'], $node['port']);
    //$nodes[$index]->setSSL($certificate); optionally enable SSL here
    $whitelist[$node['ip'] . ':8333'] = TRUE;
}

// query each node for all of its peers
$connectedPeers = array();
foreach ($nodes as $index => $node) {
    if (FALSE === $node->getpeerinfo()) {
        echo 'ERROR getting peer info from ' . $node->getHost() . ':' . $node->getPort() . "\n";
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
            $node->addnode($peer['addr'], 'remove');
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

        $node->addnode($whitelist, 'onetry');
        $node->addnode($whitelist, 'add');
    }
}
