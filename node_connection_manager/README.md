Bitcoin Node Connection Manager
===============

This PHP script uses bitcoind's RPC API to rebalance the connections between a set of nodes you operate.
The goal is to minimize redundant peer node connections amongst your pool to maximize the number of uniquely connected peers.

Getting Started
---------------
1. Add the IP Addresses and corresponding RPC usernames / passwords to the $nodes array in node_rebalance.php:

	`$nodes = array(array('ip' => '127.0.0.1', 'port' => '8332', 'username' => 'rpcuser', 'password' => 'rpcpw'));`
2. Perform a test run of the rebalance script via the command line and check output for errors / success:

	`php node_rebalance.php`

3. Automate rebalancing by adding cron entry to call script. For example, in /etc/crontab:

	`/30 * * * *   root   /path/to/node_rebalance.php > /dev/null`
