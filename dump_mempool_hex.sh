#!/usr/bin/env bash

for line in $(./bitcoin-cli getrawmempool); do
	hash=$(echo $line | tr -cd '[:alnum:]._-')
	echo $(./bitcoin-cli getrawtransaction $hash)
done
