#!/bin/bash
# This utility script checks the balance of the configured bitcoin addresses every 10 minutes
# and, if the balance changes, sends an alert email to the configured address

btc_addresses=( 12c6DSiU4Rq3P4ZxziKxzrL5LmMBrzjrJX 1FfmbHfnpaZjKFvyi1okTjJJusN455paPH )
email_address='your.email@domain.com'

# initialize balances
btc_balances=()
for (( i = 0; i < ${#btc_addresses[@]}; i++ )); do
    btc_balances[i]=$(curl -s https://blockchain.info/q/addressbalance/${btc_addresses[$i]})
    sleep 10 # respect blockchain.info API rate limit guidelines
done

# daemon
while [ true ]; do
    sleep 600 # only check addresses balances once per ~block

    # check the balance of each address
    for (( i = 0; i < ${#btc_addresses[@]}; i++ )); do
        new_balance=$(curl -s https://blockchain.info/q/addressbalance/${btc_addresses[$i]})

        if [ $new_balance -ne ${btc_balances[$i]} ]; then
            old_formatted=$(echo "${btc_balances[$i]}/100000000" | bc -l | sed 's/0*$//')
            new_formatted=$(echo "$new_balance/100000000" | bc -l | sed 's/0*$//')
            mail -s "BTC Transaction Alert" $email_address <<< "The balance of ${btc_addresses[$i]} has changed from $old_formatted to $new_formatted BTC!"
            btc_balances[i]=$new_balance
        fi
        sleep 10
    done
done
