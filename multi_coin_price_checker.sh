#!/bin/bash

coins=() # coin names
counts=() # number of each coin

coins+=( BTC )
counts+=( 10 )

coins+=( LTC )
counts+=( 10 )

coins+=( PPC )
counts+=( 10 )

coins+=( NMC )
counts+=( 10 )

coins+=( TRC )
counts+=( 10 )

coins+=( FTC )
counts+=( 10 )

coins+=( ANC )
counts+=( 10 )

coins+=( XPM )
counts+=( 10 )

coins+=( DVC )
counts+=( 10 )

coins+=( DOGE )
counts+=( 100000 )

headers=( "Coin" "Count" "Ex Rate" "Price Per" "Total USD" )

exchange=btc

tableVertical="∣"
tableHorizontal="="
tableJoint="#"

countsFormat="%'.8f"
exchangeRateFormat="%'.8f"
pricePerCoinFormat="%'.8f"
totalValueFormat="%'.2f"

cellPadding="  "
stringCellFormat="$cellPadding%s$cellPadding"
numericCellFormat="$cellPadding%s$cellPadding"
currencyCellFormat="$cellPadding\$ %s$cellPadding"

tablePadding="   "
rowStart="$tablePadding$tableVertical"
dividerStart="$tablePadding$tableJoint"

exchangeRates=( )
prices=( )
totals=( )
stringLengths=( )

totalValue=0
btcAverage=

currencyRegEx="\([0-9]\+.\)\?[0-9]\+\([eE][+-]\?[0-9]\+\)\?"


function printUsage() {
	errorString=$1

	if [ -n "$errorString" ]; then
		echo -e "\n$1"
	fi

	echo -e "\nUsage: $(basename "$0") [-e currency] [-p float] -- Fetch current cryptocoin prices."
	echo -e "Where:"
	echo -e "   -e  specifies which currency to exchange to: [btc, usd] (default: btc)"
	echo -e "   -p  uses a hypothetical value for BTC (default: Average BTC Price)\n"
	echo -e "   -h  shows this help menu"
}

function usesVircurex() {
	base=$1
	alt=$2
	
	if [[ "$base" == "dvc" || "$base" == "anc" || "$base" == "doge" ]]; then
		echo true
	elif [[ "$alt" == "usd" && ( "$base" == "ppc" || "$base" == "trc" ) ]]; then
		echo true
	else
		echo false
	fi
}

function getVircurexExchangeRate() {
	base=$1
	alt=$2

	json=$(curl https://api.vircurex.com/api/get_info_for_1_currency.json?base=$base\&alt=$alt -s)

	if [[ "$json" == *"null"* ]]; then
		echo 0
	else
		echo $json | grep -o "\"last_trade\":\"$currencyRegEx\"" | grep -o  "$currencyRegEx"
	fi
}

function getBtceExchangeRate() {
	base=$1
	alt=$2

	json=$(curl https://btc-e.com/api/2/${base}_${alt}/ticker -s)

	if [[ "$json" == *"invalid pair"* ]]; then
		echo 0
	else
		echo $json | grep -o "\"avg\":$currencyRegEx" | grep -o "$currencyRegEx"
	fi
}

function getExchangeRate() {
	base=$(echo $1 | tr '[:upper:]' '[:lower:]')
	alt=$(echo $2 | tr '[:upper:]' '[:lower:]')

	if [ "$base" == "btc" ]; then
		if [ "$alt" == "btc" ]; then
			echo 1
		else
			echo $btcAverage
		fi

		return 1
	fi

	if $(usesVircurex $base $alt); then
		rate=$(getVircurexExchangeRate $base $alt)
	else	
		rate=$(getBtceExchangeRate $base $alt)
	fi

	echo $(printf "%.8f" "$rate")
}

function maxStringLength() {
	arrayName=$1[@]
	array=("${!arrayName}")
	maxLength=0

	for e in "${array[@]}"; do
		[ ${#e} -gt $maxLength ] && maxLength=${#e}
	done

	echo $maxLength
}

function repeat() {
	character=$1
	count=$2

	if [ $count -gt 0 ]; then
		printf "%0.s$character" $(seq 1 $count)
	fi
}

function center() {
	string=$1
	boxWidth=$2
	stringLength=${#string}
	
	leftPadding=$(((1+boxWidth-stringLength)/2))
	rightPadding=$(((boxWidth-stringLength)/2))

	repeat " " $leftPadding
	printf "$string"
	repeat " " $rightPadding
}

function printDivider() {
	echo -e "$dividerStart\c"
	echo -e "$(repeat $tableHorizontal $coinsLength)$tableJoint\c"
	echo -e "$(repeat $tableHorizontal $countsLength)$tableJoint\c"
	echo -e "$(repeat $tableHorizontal $exchangeRatesLength)$tableJoint\c"
	echo -e "$(repeat $tableHorizontal $pricesLength)$tableJoint\c"
	echo -e "$(repeat $tableHorizontal $totalsLength)$tableJoint\c"
	echo -e "$(repeat $tableHorizontal $coinsLength)$tableJoint"
}

function getRowWidth() {
	tableJointWidth=${#tableJoint}
	paddingWidth=${#tablePadding}
	echo $(((coinsLength*2)+countsLength+exchangeRatesLength+pricesLength+totalsLength+(tableJointWidth*7)+(paddingWidth*2)))
}

function printTitle() {
	printf "\n\n"
	center "Current Coin Prices" $(getRowWidth)
	printf "\n\n"
}

function printHeader() {
	printTitle
	
	printDivider

	echo -e "$rowStart\c"
	echo -e "$(center "${headers[0]}" $coinsLength)$tableVertical\c"
	echo -e "$(center "${headers[1]}" $countsLength)$tableVertical\c"
	echo -e "$(center "${headers[2]}" $exchangeRatesLength)$tableVertical\c"
	echo -e "$(center "${headers[3]}" $pricesLength)$tableVertical\c"
	echo -e "$(center "${headers[4]}" $totalsLength)$tableVertical\c"
	echo -e "$(center "${headers[0]}" $coinsLength)$tableVertical"
	
	printDivider
}

function printLine() {
	index=$1
	echo -e "$rowStart\c"
	echo -e "$(center "${coins[$index]}" $coinsLength)$tableVertical\c"
	echo -e "$(center "${counts[$index]}" $countsLength)$tableVertical\c"
	echo -e "$(center "${exchangeRates[$index]}" $exchangeRatesLength)$tableVertical\c"
	echo -e "$(center "${prices[$index]}" $pricesLength)$tableVertical\c"
	echo -e "$(center "${totals[$index]}" $totalsLength)$tableVertical\c"
	echo -e "$(center "${coins[$index]}" $coinsLength)$tableVertical"
}

function printFooter() {
	printDivider

	printf "\n"
	center "$(printf "Total Value:   \$ $totalValueFormat" "$totalValue")" $(getRowWidth)
	printf "\n\n"
}









# Parse for command line args
while getopts ":e:p:h" opt; do
	case $opt in
		e)
			option=$(echo $OPTARG | tr '[:upper:]' '[:lower:]')
			case $option in
				usd|btc)
					exchange=$option
					;;
				*)
					printUsage "invalid exchange rate '$OPTARG'"
					exit 1
					;;
			esac
			;;
		p)
			if [[ $OPTARG =~ ^[0-9]*\.?[0-9]+$ ]]; then
				btcAverage=$OPTARG
			else
				printUsage "invalid BTC price '$OPTARG'"
				exit 1
			fi
			;;
		h)
			printUsage
			exit 0
			;;
		\?)
			printUsage "invalid option: '$OPTARG'"
			exit 1
			;;
		:)
			printUsage "option '$OPTARG' requires an argument"
			exit 1
			;;
	esac
done

shift $((OPTIND - 1))



# Fetch the current average BTC price in USD (only if not specified in command line options)
if [[ -z "$btcAverage" ]]; then
	btcAverage=$( \
	   curl https://api.bitcoinaverage.com/ticker/USD/ -s \
	   | grep -o '"last": [0-9.]\+' \
	   | grep -o '[0-9.]\+' \
	)

	if [[ -z "$btcAverage" ]]; then
		echo "Unable to fetch the BTC average!" >&2
		exit 1
	fi
fi




# Compute all the coin prices
for (( i = 0; i < ${#coins[@]}; i++ )); do

	coinName="${coins[$i]}"
	coinCount="${counts[$i]}"

	exchangeRate=$(getExchangeRate $coinName $exchange)

	if [ "$exchange" == "usd" ]; then
		pricePerCoin=$exchangeRate
	else
		pricePerCoin=$(echo "$exchangeRate*$btcAverage" | bc)
	fi

	totalCoinValue=$(echo "$pricePerCoin*$coinCount" | bc)
	totalValue=$(echo "$totalValue+$totalCoinValue" | bc)
	
	counts[$i]=$(printf "$countsFormat" $coinCount)
	exchangeRates[$i]=$(printf "$exchangeRateFormat" $exchangeRate)
	prices[$i]=$(printf "$pricePerCoinFormat" $pricePerCoin)
	totals[$i]=$(printf "$totalValueFormat" $totalCoinValue)

done



# Determine the max string length in each array
coinsLength=$(maxStringLength coins)
countsLength=$(maxStringLength counts)
exchangeRatesLength=$(maxStringLength exchangeRates)
pricesLength=$(maxStringLength prices)
totalsLength=$(maxStringLength totals)



# Iterate over all arrays and pad out to the max, then right align; skip headers
for (( i = 0; i < ${#coins[@]}; i++ )); do
	coins[i]=$(printf "$stringCellFormat" "$(printf "%${coinsLength}s" ${coins[$i]})")
	counts[i]=$(printf "$numericCellFormat" "$(printf "%${countsLength}s" ${counts[$i]})")
	exchangeRates[i]=$(printf "$numericCellFormat" "$(printf "%${exchangeRatesLength}s" ${exchangeRates[$i]})")
	prices[i]=$(printf "$currencyCellFormat" "$(printf "%${pricesLength}s" ${prices[$i]})")
	totals[i]=$(printf "$currencyCellFormat" "$(printf "%${totalsLength}s" ${totals[$i]})")
done



# Format all of the header cells
for (( i = 0; i < ${#headers[@]}; i++ )); do
	headers[i]=$(printf "$stringCellFormat" "${headers[$i]}")
done



# Push all the headers into the arrays
coins=( "${headers[0]}" "${coins[@]}" )
counts=( "${headers[1]}" "${counts[@]}" )
exchangeRates=( "${headers[2]}" "${exchangeRates[@]}" )
prices=( "${headers[3]}" "${prices[@]}" )
totals=( "${headers[4]}" "${totals[@]}" )



# Recalculate the max string length in each array
coinsLength=$(maxStringLength coins)
countsLength=$(maxStringLength counts)
exchangeRatesLength=$(maxStringLength exchangeRates)
pricesLength=$(maxStringLength prices)
totalsLength=$(maxStringLength totals)



# Print out the table
printHeader

for (( i = 1; i < ${#coins[@]}; i++ )); do
	printLine $i
done

printFooter



