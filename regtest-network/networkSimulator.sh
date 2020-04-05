#!/usr/bin/env bash
# Copyright (c) 2014 The Bitcoin Core developers
# Distributed under the MIT software license, see the accompanying
# file COPYING or http://www.opensource.org/licenses/mit-license.php.

# Create 4 nodes and have them generate blocks and forks

set -f

BITCOIND=/usr/local/bin/bitcoind
CLI=/usr/local/bin/bitcoin-cli

DIR="${BASH_SOURCE%/*}"
SENDANDWAIT="${DIR}/send.sh"
if [[ ! -d "$DIR" ]]; then DIR="$PWD"; fi
. "$DIR/util.sh"

D=$(mktemp -d /tmp/test.XXXXX)

# Create four nodes

D1=${D}/node1
CreateDataDir $D1 port=18444 rpcport=12001
B1ARGS="-datadir=$D1 -debug=mempool"
$BITCOIND $B1ARGS &
B1PID=$!

D2=${D}/node2
CreateDataDir $D2 port=18445 rpcport=12002
B2ARGS="-datadir=$D2 -debug=mempool"
$BITCOIND $B2ARGS &
B2PID=$!

D3=${D}/node3
CreateDataDir $D3 port=18446 rpcport=12003
B3ARGS="-datadir=$D3 -debug=mempool"
$BITCOIND $B3ARGS &
B3PID=$!

D4=${D}/node4
CreateDataDir $D4 port=18447 rpcport=12004
B4ARGS="-datadir=$D4 -debug=mempool"
$BITCOIND $B4ARGS &
B4PID=$!

# Wait until all four nodes are at the same block number
function WaitBlocks {
    while :
    do
        sleep 1
        declare -i BLOCKS1=$( GetBlocks $B1ARGS )
        declare -i BLOCKS2=$( GetBlocks $B2ARGS )
        declare -i BLOCKS3=$( GetBlocks $B3ARGS )
        declare -i BLOCKS4=$( GetBlocks $B4ARGS )
        if (( BLOCKS1 == BLOCKS2 && BLOCKS2 == BLOCKS3 && BLOCKS3 == BLOCKS4))
        then
            break
        fi
        echo "Waiting for block heights to sync across nodes"
    done
}

# Wait until node has $N peers
function WaitPeers {
    while :
    do
        declare -i PEERS=$( $CLI $1 getconnectioncount )
        if (( PEERS >= "$2" ))
        then
            break
        fi
        echo "Waiting for node $1 to reach $2 peers"
        sleep 1
    done
}

# Disconnect node $1, generate blocks & txs, reconnect to peers
function GenerateFork {
    BARGS="B${1}ARGS"
    BARGS="${!BARGS}"
    BPID="B${1}PID"
    BPID="${!BPID}"

    # send 1 BTC to every node from node 1; txs will be mined on both forks
    FundAllNodes 1

    # restart node $1 with no connection
    $CLI $BARGS stop > /dev/null 2>&1
    wait $BPID
    $BITCOIND $BARGS &
    let B${1}PID="$!"

    # send 1 BTC to every node from node 1; 3 txs will be mined on orphan fork
    FundAllNodes 1

    # Send 1 BTC from node N to node 1; tx will be mined on new longer fork
    B1ADDRESS=$( $CLI $B1ARGS getnewaddress )
    TX=$( $CLI $BARGS sendtoaddress $B1ADDRESS 1.0)

    # generate blocks on every node but generate a longer chain on node $N
    $CLI $B1ARGS setgenerate true 1
    $CLI $B2ARGS setgenerate true 2
    $CLI $B3ARGS setgenerate true 3
    $CLI $B4ARGS setgenerate true 4
    $CLI $BARGS setgenerate true 10

    #reconnect node $N to node 1
    $CLI $BARGS addnode 127.0.0.1:18444 onetry
    WaitPeers "$BARGS" 1
}

# Sends $N BTC from Node 1 to every other node & mines a block to confirm the txs
function FundAllNodes {
    B2ADDRESS=$( $CLI $B2ARGS getnewaddress )
    B3ADDRESS=$( $CLI $B3ARGS getnewaddress )
    B4ADDRESS=$( $CLI $B4ARGS getnewaddress )

    # send N BTC to every node from node 1
    TX1=$( $CLI $B1ARGS sendtoaddress $B2ADDRESS ${1})
    TX2=$( $CLI $B1ARGS sendtoaddress $B3ADDRESS ${1})
    TX3=$( $CLI $B1ARGS sendtoaddress $B4ADDRESS ${1})

    # confirm the txs
    $CLI $B1ARGS setgenerate true 1
}

# Sends $N BTC from each node to a different node
function SendRandomTransactions {
    B1ADDRESS=$( $CLI $B1ARGS getnewaddress )
    B2ADDRESS=$( $CLI $B2ARGS getnewaddress )
    B3ADDRESS=$( $CLI $B3ARGS getnewaddress )
    B4ADDRESS=$( $CLI $B4ARGS getnewaddress )

    # send N BTC to every node from node 1
    TX1=$( $CLI $B1ARGS sendtoaddress $B2ADDRESS ${1})
    TX2=$( $CLI $B2ARGS sendtoaddress $B3ADDRESS ${1})
    TX3=$( $CLI $B3ARGS sendtoaddress $B4ADDRESS ${1})
    TX4=$( $CLI $B4ARGS sendtoaddress $B2ADDRESS ${1})
}

echo "Started four regtest nodes"

# start building chain on node 1
$CLI $B1ARGS setgenerate true 2

# connect B2 to B1:
$CLI $B2ARGS addnode 127.0.0.1:18444 onetry
WaitPeers "$B1ARGS" 1

# connect B3 to B1:
$CLI $B3ARGS addnode 127.0.0.1:18444 onetry

# connect B4 to B1:
$CLI $B4ARGS addnode 127.0.0.1:18444 onetry

# ensure all peers are connected
WaitPeers "$B1ARGS" 3
WaitPeers "$B2ARGS" 1
WaitPeers "$B3ARGS" 1
WaitPeers "$B4ARGS" 1
WaitBlocks

echo "Generating network activity!"
sleep 4 # give indexer a few seconds to get out of bulk load mode

# generate 120 blocks on node 1 so that we can spend coinbase outputs
$CLI $B1ARGS setgenerate true 200
WaitBlocks
FundAllNodes 1000    # prevent insufficient funds errors when sending future txs
$CLI $B1ARGS setgenerate true 20
WaitBlocks

# begin generating forks and random txs
for i in `seq 1 10`;
do
    GenerateFork 2
    $CLI $B1ARGS setgenerate true 2
    WaitBlocks
    SendRandomTransactions 2
    $CLI $B2ARGS setgenerate true 2
    SendRandomTransactions 3
    $CLI $B3ARGS setgenerate true 2
    WaitBlocks
    $CLI $B4ARGS setgenerate true 2
    GenerateFork 3
    WaitBlocks
    $CLI $B4ARGS setgenerate true 2
    SendRandomTransactions 4
    WaitBlocks
    GenerateFork 4
    WaitBlocks
    GenerateFork 3
    WaitBlocks
    SendRandomTransactions 1
    $CLI $B3ARGS setgenerate true 2
    GenerateFork 2
    GenerateFork 4
    GenerateFork 2
    sleep 10
done

$CLI $B4ARGS setgenerate true 100

echo "Regtest nodes will shut down in 30 seconds"
sleep 30

$CLI $B4ARGS stop > /dev/null 2>&1
wait $B4PID
$CLI $B3ARGS stop > /dev/null 2>&1
wait $B3PID
$CLI $B2ARGS stop > /dev/null 2>&1
wait $B2PID
$CLI $B1ARGS stop > /dev/null 2>&1
wait $B1PID

echo "Tests successful, cleaning up"
rm -rf $D
exit 0
