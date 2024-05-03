#!/bin/bash
# This script is designed to trigger a permanent difficulty reset of Bitcoin's testnet
# to the minimum difficulty of 1 by ensuring that the block right before a difficulty
# adjustment has a difficulty target of 1.
# For more info see https://blog.lopp.net/the-block-storms-of-bitcoins-testnet/

pgrep -fl bfgminer
# if not found - equals to 1, start it
if [ $? -eq 1 ]
then
	height=$(~/code/bitcoin/src/bitcoin-cli getblockcount)
	remainder=$(($height % 2016))
	if [ $remainder -ge 2013 ]
	then
		~/code/bfgminer/bfgminer -S opencl:auto -o http://127.0.0.1:18332 -u user -p password --generate-to mwnsh33QHVXYDLWUvZwRizzp72SEaRLnf2 --coinbase-sig "Mined by Lopp (⌐■_■)" & 
		sleep 60
		pkill bfgminer
	else
		echo "too soon!"
		exit
	fi
else
echo "bfgminer running - do nothing"
exit;
fi
