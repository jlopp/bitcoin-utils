#!/bin/bash

# Check if an argument is provided and it's a numeric value
if [ $# -lt 1 ] || ! [[ $1 =~ ^[0-9]+$ ]]; then
    echo "ERROR: argument 1 must be an integer"
    exit 1
fi

blockHeight="$1"

declare -A estimateStdDev=(
    [100]=6.23
    [200]=4.53
    [300]=3.85
    [400]=3.48
    [500]=3.24
    [600]=3.11
    [700]=3.04
    [800]=3.04
    [900]=3.01
)

# Start off giving a 10% weight to the 1,000 trailing block estimate
longEstimate=$(bitcoin-cli getnetworkhashps 1000 "$blockHeight" | awk -FE 'BEGIN{OFMT="%20"} {print $1 * (10 ^ $2)}')
blendedEstimate=$(echo "$longEstimate * 0.1" | bc -l)

for ((trailingBlocks=100; trailingBlocks<1000; trailingBlocks+=100)); do
    shortEstimate=$(bitcoin-cli getnetworkhashps "$trailingBlocks" "$blockHeight" | awk -FE 'BEGIN{OFMT="%20"} {print $1 * (10 ^ $2)}')

    estimateDiff=$(bc -l <<< "$longEstimate - $shortEstimate")
    # if current short hashrate estimate is less than 1 std deviation from long estimate, ignore it
    if (( $(bc -l <<< "${estimateDiff#-} < ${estimateStdDev[$trailingBlocks]}") )); then
        blendedEstimate=$(echo "$blendedEstimate + $longEstimate * 0.1" | bc -l)
    else
        # find how many of the recent ($trailingBlocks) short estimates have also been above/below the long estimate
        # and weight the short estimate
        if ((shortEstimate > longEstimate)); then
            higher=0
            for ((height=blockHeight-trailingBlocks; height<blockHeight; height++)); do
                if (( $(bitcoin-cli getnetworkhashps 1000 "$height" | awk -FE 'BEGIN{OFMT="%20"} {print $1 * (10 ^ $2)}' | bc -l) > longEstimate )); then
                    ((higher++))
                fi
            done
            shortWeight=$(bc -l <<< "$higher / $trailingBlocks")
        else
            shortWeight=0
        fi
        blendedEstimate=$(echo "($longEstimate * (1 - $shortWeight) + $shortEstimate * $shortWeight) * 0.1 + $blendedEstimate" | bc -l)
    fi
done

echo "$blendedEstimate"
