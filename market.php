<?php

/*
    BIOHAZARD
    TODO: display waiting rate
    TODO: display min profit

    TODO: specify profit %
*/

class market
{
    public $locked = false;

    public $maker_fee;
    public $taker_fee;
    public $client;

    public $first_trade_currency;
    public $first_trade_amount;

    public $win_percent;

    public $from_currency;
    public $from_balance_first = 0;
    public $from_balance_last = 0;
    public $from_balance;
    public $from_min_trade;

    public $to_currency;
    public $to_balance_first = 0;
    public $to_balance_last = 0;
    public $to_balance;
    public $to_min_trade;

    public $sell_rate;
    public $buy_rate;
    public $high_rate;
    public $low_rate;

    public $buy_amount;
    public $buy_amount_after;
    public $sell_amount;
    public $sell_amount_after;

    private static $min_trades = ['XRP'=>1,'USDT'=>1,'ETC'=>1,'BTC'=>0.0001];

    public function __construct ($params = [])
    {
        if (
            !isset($params['pair']) ||
            !isset($params['win-percent']) ||
            !isset($params['poloniex-key']) ||
            !isset($params['poloniex-secret']) ||
            !isset($params['first-trade-currency']) ||
            !isset($params['first-trade-amount'])
        )
        {
            $this->log ("error","some config parameter is missing");
            exit;
        }


        /*
            setup from_currency and to_currency
        */
        if (!$this->extract_pair($params['pair']))
        {
            $this->log ("error", "incorrect pair provided");
            exit;
        }
        list ($this->from_currency, $this->to_currency) = $this->extract_pair($params['pair']);

        /*
            create data dir if not exists
        */
        if (!is_dir($this->data_dir()))
        {
            mkdir($this->data_dir(), 0777, true);
        }

        /*
            first trade amount will be set as [from/to]_balance_last
            if not found in file
        */
        $this->first_trade_currency = $params['first-trade-currency'];
        $this->first_trade_amount = $params['first-trade-amount'];

        $this->from_min_trade = self::$min_trades [$this->to_currency];
        $this->to_min_trade = self::$min_trades[$this->to_currency];

        $this->win_percent = floatval($params['win-percent'])/100;

        /*
            setup from last balance
        */
        if (file_exists($this->from_currency_file('last')))
        {
            $result = trim(file_get_contents($this->from_currency_file('last')));
            if ($result)
            {
                $this->from_balance_last = $result;
            }
        }
        else if ($this->first_trade_currency==$this->from_currency)
        {
            $this->from_balance_last = $this->first_trade_amount;
        }
        /*
            setup from first balance
        */
        if (file_exists($this->from_currency_file('first')))
        {
            $result = trim(file_get_contents($this->from_currency_file('first')));
            if ($result)
            {
                $this->from_balance_first = $result;
            }
        }

        /*
            setup to last balance
        */
        if (file_exists($this->to_currency_file('last')))
        {
            $result = trim(file_get_contents ($this->to_currency_file('last')));
            if ($result)
            {
                $this->to_balance_last = $result;
            }
        }
        else if ($this->first_trade_currency==$this->to_currency)
        {
            $this->to_balance_last = $this->first_trade_amount;
        }

        /*
            setup to first balance
        */
        if (file_exists($this->to_currency_file('first')))
        {
            $result = trim(file_get_contents($this->to_currency_file('first')));
            if ($result)
            {
                $this->to_balance_first = $result;
            }
        }

        //debug ($this);
        //exit;

        $this->client = new poloniex ($params['poloniex-key'], $params['poloniex-secret']);
    }
    public function extract_pair ($pair)
    {
        $pair = trim ($pair);
        $separator = strpos($pair,'_');
        if ($separator===false)
        {
            return false;
        }
        $from = trim(substr($pair, 0, $separator));
        $to = trim(substr($pair, $separator+1));
        if (trim($from)=='' || trim($to)=='' || strlen($from)<3 || strlen($to)<3)
        {
            return false;
        }
        return [$from,$to];
    }
    public function data_dir ()
    {
        return './data';
    }
    public function from_currency_file ($type='last')
    {
        return $this->data_dir().'/'.strtolower($this->from_currency).'.'.$type;
    }
    public function to_currency_file ($type='last')
    {
        return $this->data_dir().'/'.strtolower($this->to_currency).'.'.$type;
    }
    public function pair ()
    {
        return $this->from_currency.'_'.$this->to_currency;
    }
    public static function number ($amount)
    {
        return number_format ($amount, 8, '.', '');
    }
    public function trade ()
    {
        if (!$this->refresh())
        {
            $this->log ('skip','refresh skip', \console\RED);
            return;
        }
        if ($this->buy_amount()>=$this->to_min_trade && $this->buy_profitable())
        {
            //$this->buy();
        }
        else if ($this->sell_amount()>=$this->from_min_trade && $this->sell_profitable())
        {
            //$this->sell();
        }
        return true;
    }
    public function buy_amount ()
    {
        return self::number($this->from_balance/$this->buy_rate);
    }
    public function buy_amount_after ()
    {
        return self::number($this->buy_amount()*(1-$this->taker_fee));
    }
    public function time ()
    {
        return @date("H:i:s");
    }
    public function buy_rate_next ()
    {
        return self::number($this->from_balance/($this->to_balance_last*(1+$this->win_percent+$this->taker_fee)));
    }
    //BUY XRP
    public function buy_log ($color)
    {
echo
\console\color(
"[BUY ".$this->to_currency."] ".$this->time()."\n".
"   Buy profitable by ",$color).\console\color(self::number($this->buy_amount_after()-$this->to_balance_last),\console\GRAY)." ".\console\color($this->to_currency."\n".
"   1 ".$this->to_currency.' = '.$this->buy_rate." ".$this->from_currency." >> ".$this->buy_rate_next()." ".$this->from_currency."\n".
"   Rate needs to change by ", $color).\console\color(self::number($this->buy_rate_next()-$this->buy_rate),\console\RED).\console\color(" ".$this->from_currency."\n".
"   Balance ".$this->from_balance." ".$this->from_currency."\n".
"   Next min profit ".self::number($this->to_balance_last*$this->win_percent)." ".$this->to_currency." ~".($this->win_percent*100)."%\n".
"   Total profited ".self::number($this->from_balance-$this->from_balance_first)." ".$this->from_currency,$color)."\n";

    echo \console\progress (
            " ".$this->buy_rate_next()."        ".$this->buy_rate."        ".$this->high_rate." ",
            $this->buy_rate_next(), //this is what rate we need to buy
            $this->buy_rate, //this is current rate
            $this->high_rate
        )."\n";

    }
    public function sell_rate_next ()
    {
        return self::number (($this->from_balance_last*(1+$this->win_percent+$this->taker_fee))/$this->to_balance);
    }
    //SELL XRP
    public function sell_log ($color)
    {
echo
\console\color(
"[SELL ".$this->to_currency."] ".$this->time()."\n".
"   Sell profitable by ",$color).\console\color(self::number($this->sell_amount_after()-$this->from_balance_last),\console\GRAY)." ".\console\color($this->from_currency."\n".
"   1 ".$this->to_currency.' = '.$this->sell_rate." ".$this->from_currency.' >> '.$this->sell_rate_next()." ".$this->from_currency."\n".
"   Rate needs to change by ", $color).\console\color(self::number($this->sell_rate_next()-$this->sell_rate),\console\RED).\console\color(" ".$this->from_currency."\n".
"   Balance ".$this->to_balance." ".$this->to_currency."\n".
"   Next min profit ".self::number($this->from_balance_last*$this->win_percent)." ".$this->from_currency." ~".($this->win_percent*100)."%\n".
"   Total profited ".self::number($this->to_balance-$this->to_balance_first)." ".$this->to_currency,$color)."\n";

    echo \console\progress (
            " ".$this->low_rate."        ".$this->sell_rate."        ".$this->sell_rate_next()." ",
            $this->low_rate,
            $this->sell_rate, //this is current rate
            $this->sell_rate_next() //this is what rate we need to sell
        )."\n";

    }
    public function buy_profitable()
    {
        if ($this->buy_amount_after()>=($this->to_balance_last*(1+$this->win_percent)))
        {
            $this->buy_log (\console\GREEN);
            return true;
        }
        $this->buy_log (\console\BLUE);
        return false;
    }
    public function sell_profitable()
    {
        if ($this->sell_amount_after()>=($this->from_balance_last*(1+$this->win_percent)))
        {
            $this->sell_log (\console\GREEN);
            return true;
        }
        $this->sell_log (\console\PINK);
        return false;
    }

    public function sell_amount ()
    {
        return self::number($this->to_balance*$this->sell_rate);
    }
    public function sell_amount_after ()
    {
        return self::number($this->sell_amount()*(1-$this->taker_fee));
    }
    public function buy_log_file ()
    {
        file_put_contents($this->data_dir().'/trade.log',
            @date("Y-m-d H:i")." ".
            "BUY  ".str_pad($this->buy_amount(),13,' ',STR_PAD_LEFT)." ".$this->to_currency." ".
            "WITH ".str_pad($this->from_balance,13,' ',STR_PAD_LEFT)." ".$this->from_currency." ".
            "AT ".$this->buy_rate." ".$this->from_currency."\n",
            FILE_APPEND
        );
    }
    public function sell_log_file ()
    {
        file_put_contents($this->data_dir().'/trade.log',
            @date("Y-m-d H:i")." ".
            "SELL ".str_pad($this->to_balance,13,' ',STR_PAD_LEFT)." ".$this->to_currency." ".
            "FOR  ".str_pad($this->sell_amount(),13,' ',STR_PAD_LEFT)." ".$this->from_currency." ".
            "AT ".$this->sell_rate." ".$this->from_currency."\n",
            FILE_APPEND
        );
    }
    public function buy ()
    {
        $result = null;
        if ($this->buy_amount()>=$this->to_min_trade)
        {
            $this->log
            (
                "buy",
                "\n   Buying ".$this->buy_amount()." ".$this->to_currency." with ".$this->from_balance." ".$this->from_currency.
                "\n   1 ".$this->to_currency." = ".$this->buy_rate." ".$this->from_currency,
                \console\YELLOW
            );
            $result = $this->client->buy ($this->pair(), $this->buy_rate, $this->buy_amount());
            //debug ($result,'buy result');
        }
        else
        {
            $this->log ("buy", "not buying no minimum trade criteria met", \console\RED);
        }

        if (is_array($result) && !isset($result['error']))
        {
            file_put_contents($this->from_currency_file(), $this->from_balance);
            $this->buy_log_file ();
            \termux\notification
            (
                "BUY ".$this->buy_amount()." ".$this->to_currency,
                "WITH ".$this->from_balance." ".$this->from_currency." AT ".$this->buy_rate." ".$this->from_currency,
                "FF00FF"
            );
            $this->from_balance_last = $this->from_balance;
            $this->from_balance = 0;
        }
        else if ($result!==null)
        {
            if (!is_array($result))
            {
                $this->log ('buy '.$this->to_currency, 'buy failed', \console\RED);
            }
            else
            {
                $this->log ('buy '.$this->to_currency, 'buy failed: '.$result['error'], \console\RED);
            }

        }
    }
    public function sell ()
    {
        $result = null;
        if ($this->sell_amount()>=$this->from_min_trade)
        {
            $this->log
            (
                "sell",
                "\n   Selling ".$this->to_balance." ".$this->to_currency." for ".$this->sell_amount()." ".$this->from_currency.
                "\n   1 ".$this->to_currency." = ".$this->sell_rate." ".$this->from_currency,
                \console\YELLOW
            );
            $result = $this->client->sell ($this->pair(), $this->sell_rate, $this->to_balance);
            //debug ($result,'sell result');
        }
        else
        {
            $this->log ("sell", "not selling no minimum trade criteria met", \console\RED);
        }

        if (is_array($result) && !isset($result['error']))
        {
            file_put_contents($this->to_currency_file(), $this->to_balance);
            $this->sell_log_file ();
            \termux\notification
            (
                "SELL ".$this->to_balance." ".$this->to_currency,
                "FOR ".$this->sell_amount()." ".$this->from_currency." AT ".$this->sell_rate." ".$this->from_currency,
                "FF00FF"
            );
            $this->to_balance_last = $this->to_balance;
            $this->to_balance = 0;
        }
        else if ($result!==null)
        {
            if (!is_array($result))
            {
                $this->log ('sell '.$this->to_currency, 'sell failed', \console\RED);
            }
            else
            {
                $this->log ('sell '.$this->to_currency, 'sell failed: '.$result['error'], \console\RED);
            }
        }
    }
    public function refresh()
    {
        static $debug = false;
        $result = $this->client->get_open_orders ($this->pair());
        if ($result)
        {
            //debug ($result);
            $this->locked = true;
            $orders = '';
            foreach ($result as $order)
            {
                $orders .= ' '.$order['type'].' '.$order['startingAmount'].' '.$this->to_currency.' rate '.$order['rate'].' '.$this->to_currency;
            }
            $this->log ('skip','pending orders:'.$orders);
            return false;
        }
        else
        {
            $this->locked = false;
        }

        #balances
        $result = $this->client->get_balances();
        if (!$result || !isset($result[$this->from_currency]) || !isset($result[$this->to_currency]))
        {
            $this->log ('skip','error retrieving balances');
            return false;
        }
        $this->from_balance = $result[$this->from_currency];
        $this->to_balance = $result[$this->to_currency];

        if ($this->from_balance>0.0000001 && $this->to_balance_last==0)
        {
            $this->log ('error','you have not set how much '.$this->to_currency.' you have to buy in your first trade',\console\RED);
            exit;
        }
        if ($this->to_balance>0.0000001 && $this->from_balance_last==0)
        {
            $this->log ('error','you have not for how much '.$this->from_currency.' you have to sell in your first trade',\console\RED);
            exit;
        }

        #fees
        $result = $this->client->get_fee_info();
        if (!$result)
        {
            $this->log ('skip','error retrieving fees');
            return false;
        }
        $this->maker_fee = $result['makerFee'];
        $this->taker_fee = $result['takerFee'];
        if (!$this->maker_fee || !$this->taker_fee)
        {
            $this->log ('skip','error retrieving fees');
            return false;
        }

        #rates
        $result = $this->client->get_ticker ($this->pair());
        if (!$result)
        {
            $this->log ('skip','error retrieving rates');
            return false;
        }
        $this->buy_rate = $result['lowestAsk'];
        $this->sell_rate = $result['highestBid'];
        $this->high_rate = $result['high24hr'];
        $this->low_rate = $result['low24hr'];

        if ($this->from_balance>0.0000001 && !$this->from_balance_first)
        {
            file_put_contents($this->from_currency_file('first'), $this->from_balance);
            $this->from_balance_first = $this->from_balance;
        }
        if ($this->to_balance>0.0000001 && !$this->to_balance_first)
        {
            file_put_contents($this->to_currency_file('first'), $this->to_balance);
            $this->to_balance_first = $this->to_balance;
        }

        #stats
        $this->buy_amount = $this->buy_amount();
        $this->buy_amount_after = $this->buy_amount_after();
        $this->sell_amount = $this->sell_amount();
        $this->sell_amount_after = $this->sell_amount_after();

        if (!$debug)
        {
            debug ($this);
            $debug = true;
        }

        //exit;

        return true;

    }

    public function log ($status, $message, $color=null)
    {
        $message = "[".strtoupper($status)."] ".$this->time()." ".$message;
        if ($color!==null)
        {
            echo \console\color($message, $color)."\n";
        }
        else
        {
            echo $message."\n";
        }
    }
}