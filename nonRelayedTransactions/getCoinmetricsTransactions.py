from coinmetrics.api_client import CoinMetricsClient
from datetime import timedelta

client = CoinMetricsClient('API_KEY')

if __name__ == '__main__':
	tx_tracker = client.get_transaction_tracker(
	asset='btc',
	start_time='2022-01-01T00:00:00Z',
	end_time='2024-01-01T00:00:00Z',
	page_size='10000'
	).parallel(time_increment=timedelta(days=1)).export_to_csv_files(data_directory='./txhistory')

## To find the transactions that were not seen as unconfirmed, run the following in the txhistory directory:
## grep --invert-match UNCONFIRMED btc_transaction-tracker*.csv | grep --invert-match txid