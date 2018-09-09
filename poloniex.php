<?php

class poloniex
{
	protected $api_key;
	protected $api_secret;
	protected $trading_url = "https://poloniex.com/tradingApi";
	protected $public_url = "https://poloniex.com/public";
	public static $min = ['USDT'=>1, 'BTC'=>0.0001, 'ETH'=>0.0001, 'XMR'=>0.0001];
	public static $max = [];

	public function __construct ($api_key, $api_secret)
	{
		$this->api_key = $api_key;
		$this->api_secret = $api_secret;
	}
	public function min ($currency)
	{
		if (isset(self::$min[$currency]))
		{
			return self::$min[$currency];
		}
		return false;
	}
	public function max ($currency)
	{
		if (isset(self::$max[$currency]))
		{
			return self::$max[$currency];
		}
		return false;
	}
	private function query (array $request = array())
	{
		// API settings
		$key = $this->api_key;
		$secret = $this->api_secret;

		debug ($request);

		// generate a nonce to avoid problems with 32bit systems
		$microtime = explode(' ', microtime());
		$request['nonce'] = $microtime[1].substr($microtime[0], 2, 6);

		// generate the POST data string
		$post_data = http_build_query ($request, '', '&');
		$sign = hash_hmac ('sha512', $post_data, $secret);

		// generate the extra headers
		$headers = array
		(
			'Key: '.$key,
			'Sign: '.$sign,
		);

		// curl handle (initialize if required)
		static $handle = null;
		if (is_null($handle))
		{
			$handle = curl_init();
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handle, CURLOPT_USERAGENT,
				'Mozilla/4.0 (compatible; Poloniex PHP bot; '.php_uname('a').'; PHP/'.phpversion().')'
			);
		}
		curl_setopt($handle, CURLOPT_URL, $this->trading_url);
		curl_setopt($handle, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, FALSE);

		// run the query
		try
		{
			$result = curl_exec($handle);
		}
		catch (\Exception $e)
		{
			echo "[ERROR] ".@date("H:i:s")." ".$e->getMessage()."\n";
			return false;
		}

		//if ($result === false) throw new Exception('Curl error: '.curl_error($handle));
		if ($result === false)
		{
			echo "[ERROR] ".@date("H:i:s")." ".curl_error($handle)."\n";
			return false;
		 	//throw new Exception('Curl error: '.curl_error($handle));
		}
		//echo $result;
		$decoded = json_decode($result, true);
		if (!$decoded)
		{
			//throw new Exception('Invalid data: '.$result);
			return false;
		}
		else
		{
			return $decoded;
		}
	}

	protected function retrieve_json ($url)
	{
		$request = array
		('http' =>
			array
			(
				'method'  => 'GET',
				'timeout' => 10
			)
		);
		$context = stream_context_create($request);
		$feed = file_get_contents($url, false, $context);
		$json = json_decode($feed, true);
		return $json;
	}

	public function get_balances()
	{
		return $this->query
		(
			array
			(
				'command' => 'returnBalances'
			)
		);
	}

	public function get_fees ()
	{
		$result = $this->query
		(
			array
			(
				'command' => 'returnFeeInfo'
			)
		);
		if (is_array($result) && !isset($result['error']))
		{
			$result['maker'] = &$result['makerFee'];
			$result['taker'] = &$result['takerFee'];
		}
		//debug ($result);
		return $result;
	}

	public function get_rates ()
	{
		$result = $this->retrieve_json($this->public_url.'?command=returnTicker');
		if (is_array($result) && !isset($result['error']))
		{
			foreach ($result as $key => $value)
			{
				$result[$key]['buy'] = &$result[$key]['lowestAsk'];
				$result[$key]['sell'] = &$result[$key]['highestBid'];
				$result[$key]['high'] = &$result[$key]['high24hr'];
				$result[$key]['low'] = &$result[$key]['low24hr'];
			}
		}
		//debug ($result);
		return $result;
	}

	public function get_trades ($id=null)
	{
		if ($id!==null)
		{
			return $this->query
			(
				array
				(
					'command' => 'returnOrderTrades',
					'orderNumber' => $id
				)
			);
		}
		else
		{
			return $this->query
			(
				array
				(
					'command' => 'returnTradeHistory',
					'currencyPair' => 'ALL',
					'start' => 0
				)
			);
		}
	}

	public function get_orders ()
	{
		$result = $this->query
		(
			array
			(
				'command' => 'returnOpenOrders',
				'currencyPair' => 'ALL'
			)
		);
		if (is_array($result) && !isset($result['error']))
		{
			$source = $result;
			$result = [];
			foreach ($source as $pair => $orders)
			{
				if (!isset($result[$pair]))
				{
					$result[$pair] = [];
				}
				foreach ($orders as $key => $value)
				{
					//$result[$pair][$key]['id'] = &$result[$pair][$key]['orderNumber'];
					$result[$pair][$value['orderNumber']][] = $value;
				}
			}
		}
		//debug ($result);
		return $result;
	}

	public function buy ($pair, $rate, $amount)
	{
		$result = $this->query
		(
			array
			(
				'command' => 'buy',
				'currencyPair' => strtoupper($pair),
				'rate' => $rate,
				'amount' => $amount
			)
		);
		if (is_array($result) && !isset($result['error']))
		{
			$result['id'] = &$result['orderNumber'];
			$result['trades'] = &$result['resultingTrades'];
		}
		debug ($result);
		return $result;
	}

	public function sell ($pair, $rate, $amount)
	{
		$result = $this->query
		(
			array
			(
				'command' => 'sell',
				'currencyPair' => strtoupper($pair),
				'rate' => $rate,
				'amount' => $amount
			)
		);
		if (is_array($result) && !isset($result['error']))
		{
			$result['id'] = &$result['orderNumber'];
			$result['trades'] = &$result['resultingTrades'];
		}
		debug ($result);
		return $result;
	}

}
