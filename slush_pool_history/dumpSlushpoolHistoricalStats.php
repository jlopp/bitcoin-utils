<?php

// This script reads a bunch of JSON data from slushpool's web site
// that is displayed on the table at https://slushpool.com/stats/blocks/?c=btc
// and dumps it into a CSV

echo "Block Height,Block Timestamp,Block Value,Pool Hashrate\n";

$ch = curl_init();
for ($items = 0; $items += 15; $items < 45317) {
	curl_setopt($ch, CURLOPT_URL, "https://slushpool.com/api/v1/web/scalar/tree/");
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_POST, 1);
	$postdata = '{"btc":{"blocks":{"item_count":0,"items":{"'. $items . '":null}},"price":0},"time":0,"git_head":0,"profile":{"user_id":0,"user_id_encoded":0,"tzname":0,"username":0,"coins":0},"session":0}';
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json',
												'Content-Type: application/json',
												'origin: https://slushpool.com',
												"referer: https://slushpool.com/stats/blocks/?c=btc",
												"X-CSRFTOKEN: 7bGYGaQGbUZbVyZAIU6SFZQVhFyAgzSwFu8EcUCHbIO3dnckwJgo0XQj9eWb8CiL",
												"cookie: csrftoken=7bGYGaQGbUZbVyZAIU6SFZQVhFyAgzSwFu8EcUCHbIO3dnckwJgo0XQj9eWb8CiL; sessionid=3uvpnvf67qftdorj7g7hpby2bcgy1j2g"
											));

 

	$result = json_decode(curl_exec($ch));
	$page = $result->data->btc->blocks->items->$items->data->data;
	/*
    [0] => id
    [1] => found_at
    [2] => duration
    [3] => total_shares
    [4] => value
    [5] => height
    [6] => difficulty
    [7] => hash
    [8] => luck_perc
    [9] => pool_scoring_hashrate
    [10] => state
    [11] => to_confirm
	*/
	foreach ($page as $data) {
		echo "$data[5],$data[1],$data[4],$data[9]\n";		
	}
	sleep(1);
}
curl_close($ch);