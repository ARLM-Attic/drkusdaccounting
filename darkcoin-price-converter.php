<?php

/* Kristov Atlas 2014 */

/* 
	For Darkcoin accounting DRK to USD. No warranty, use at your own risk.
	For a given Darkcoin address, prints the USD value for each received amount, based on average DRK and BTC prices for the date of the transaction.

	Code quality is low, this is a quick, produce-output-as-quickly-as-possible project.
*/

#WARNING: In order to maintain financial privacy, you should only direct this script's traffic through an anonymity network such as Tor. A new identity should be assumed for each address you lookup.

/***********\
| CONSTANTS |
\***********/

const SAMPLE_ADDRESS = 'XjAdaR6T3yBSENmBCx5Nvw5vw316g96h6R';

/**************************\
| USER-CUSTOMIZED SETTINGS |
\**************************/

$darkcoinAddress = SAMPLE_ADDRESS; #Set your Darkcoin address here

date_default_timezone_set('UTC'); #Set the timezone of the blockexplorer you're using -- I assume darkcoin.io is UTC.

/***********\
| FUNCTIONS |
\***********/

function get_drk_balance_lookup_url($address)
{
	return 'http://explorer.darkcoin.io/address/' . $address;
}

function get_btc_drk_conversion_lookup_url($start_unix, $end_unix)
{
	return 'https://poloniex.com/public?command=returnTradeHistory&currencyPair=BTC_DRK&start=' . $start_unix . '&end=' . $end_unix;
}

function get_btc_usd_conversion_lookup_url()
{
	return 'https://api.bitcoinaverage.com/history/USD/per_day_all_time_history.csv';
}

function get_start_date($html)
{
	$lines = get_array_of_lines_from_html($html);
	foreach($lines as $line)
	{
		#example: <td class="time">2014-08-29 08:51:31
		$prefix = preg_quote('<td class="time">');
		$pattern = "/$prefix(\d+-\d+-\d+)/";
		preg_match($pattern, $line, $matches);
		if ($matches[1])
		{
			return $matches[1];
		}
	}
	
	die("Couldn't find a start date on this page.");
}

function get_end_date($html)
{
	$lines = get_array_of_lines_from_html($html);
	$end_date = '';
	foreach($lines as $line)
	{
		#example: <td class="time">2014-08-29 08:51:31
		$prefix = preg_quote('<td class="time">');
		$pattern = "/$prefix(\d+-\d+-\d+)/";
		preg_match($pattern, $line, $matches);
		if ($matches[1])
		{
			$end_date = $matches[1];
		}
	}
	
	if (!$end_date)
	{
		die("Couldn't find a start date on this page.");
	}
	
	return $end_date;
}

function date_to_unix_time($date)
{
	return strtotime($date);
}

function get_array_of_lines_from_html($html)
{
	return explode(PHP_EOL, $html);
}

function get_html_contents($url)
{
	$c = curl_init($url);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	$html = curl_exec($c);
	
	if (curl_error($c))
	{
		die (curl_error($c));
	}
	
	return $html;
}

function extract_date_from_datetimestamp($datetimestamp)
{
	preg_match("/(\d+-\d+-\d+)/", $datetimestamp, $matches);
	if ($matches[1]) { return $matches[1]; }
	else { return ''; }
}

function get_average_btc_per_drk_on_date($html, $date)
{
	#return associative array
	$json = json_decode($html, true);
	
	$numCounted = 0;
	$total = '0.0';
	
	foreach ($json as $trade)
	{
		if (extract_date_from_datetimestamp($trade['date']) === $date)
		{
			$total += '' . $trade['rate'];
			$numCounted++;
		}
	}
	$avg = $total / $numCounted;
	
	return $avg;
}

function get_usd_per_btc_on_date($btc_price_html, $date)
{
	$lines = get_array_of_lines_from_html($btc_price_html);
	foreach ($lines as $line)
	{
		preg_match("/^([^,]+),[^,]+,[^,]+,([^,]+),[^,]+$/", $line, $matches);
		if ($matches[1])
		{
			$datetime = $matches[1];
			$thisDate = extract_date_from_datetimestamp($datetime);
			
			if ($thisDate === $date)
			{
				$avgPrice = $matches[2];
				return $avgPrice;
			}
		}
	}
	
	die("Couldn't find a USD/BTC price for $date\n");
}

function print_usd_per_drk_tx($address_html, $btc_drk_conversion_html, $btc_price_html)
{
	$addressLines = get_array_of_lines_from_html($address_html);
	$usd_total = '0.00';
	$i = 0;
	foreach ($addressLines as $line)
	{
		#ex: <tr class="direct"><td class="tx"><a href="../tx/086c49d6a321a600d6609ec7e06bb6b356298602429ee03a0a2eac091ff8d354#o0">086c49d6a3...</a></td><td class="block"><a href="../block/0000000000083e68116222b70872cf4226432ebaefd55105fafbf3ff74656d88">127082</a></td><td class="time">2014-08-29 08:51:31</td><td class="amount">1000</td><td class="balance">1000</td><td class="currency">DRK</td></tr>
		preg_match("/\.\.\/tx\/[\w#]+\">(\w+).*time\">(\d+-\d+-\d+).*amount\">([\d\.]+)/", $line, $matches);
		if ($matches[0])
		{
			$i++;
			$tx_hash_prefix = $matches[1];
			$date = $matches[2];
			$amount = $matches[3];
			
			$btc_drk_on_date = get_average_btc_per_drk_on_date($btc_drk_conversion_html, $date);
			
			$usd_btc_on_date = get_usd_per_btc_on_date($btc_price_html, $date);
			
			$usd_val_on_date = $amount * $btc_drk_on_date * $usd_btc_on_date;
			
			$usd_total += $usd_val_on_date;
			
			print "$i\ttx$tx_hash_prefix\t$date\t$amount DRK\t" . '$' . "$usd_val_on_date\n";
		}
	}
	print 'Total: $' . "$usd_total\n";
}


/**************\
| BEGIN SCRIPT |
\**************/

#validate address format
preg_match('/^\w+$/', $darkcoinAddress, $matches);
if (!$matches[0])
{
	die("Invalid address.");
}

#lookup data for Darkcoin address
$address_url = get_drk_balance_lookup_url($darkcoinAddress);
$address_html = get_html_contents($address_url);

print "Fetching address data from $address_url...\n";

$first_date_received = get_start_date($address_html);
$last_date_received = get_end_date($address_html);

#fetch BTC/DRK data for the time range required for the given Darkcoin address. Add 86400 seconds = 1 day because otherwise it will cut off the last day at 00:00:00
$btc_drk_url = get_btc_drk_conversion_lookup_url(date_to_unix_time($first_date_received), date_to_unix_time($last_date_received) + 86400);
print "Fetching BTC/DRK rates from $btc_drk_url...\n";
$btc_drk_html = get_html_contents($btc_drk_url);

$usd_btc_url = get_btc_usd_conversion_lookup_url();
$usd_btc_html = get_html_contents($usd_btc_url);

print "Fetching USD/BTC rates from $usd_btc_url...\n";

#print the final results for this Darkcoin address
print_usd_per_drk_tx($address_html, $btc_drk_html, $usd_btc_html);

?>