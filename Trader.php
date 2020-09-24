<?php

require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
require_once("log.php");


class Trader {

    const TICKER_PATH = '.ticker';
    private $log;
    private $bitmex;
    private $symbol;
    private $targets;
    private $amount;
    private $initialAmount;
    private $startTime;
    private $stopPx;
    private $openOrders;
    private $env;


    public function __construct($symbol, $side, $amount, $stopPx=null, $targets=null) {
        $config = include('config.php');
        $leverage = $config['leverage'];

        $this->bitmex = new BitMex($config['key'], $config['secret'], $config['testnet']);
        try {
            $this->bitmex->setLeverage($leverage, $symbol);
        } catch (Exception $e) {
            $this->log->error("Exception during set leverage.",[$e]);
        }
        $this->log = create_logger(getcwd().'/'.$symbol.'scalp.log');
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $this->symbol = $symbol;
        $this->stopPx = $stopPx;
        $this->side = $side;
        $this->initialAmount = intval($amount);

        if ($config['testnet']) {
            $this->env = 'test';
        }
        else {
            $this->env = 'prod';
        }
        $this->targets = array();
        $this->log->info("Finished Trade construction, proceeding",[]);
    }
    public function __destruct() {
        $this->log->info("Remaining open Trades or orders.", ['OpenPositions => '=>$this->are_open_positions(), "Orders => "=>$this->are_open_orders()]);
        sleep(2);
        $this->log->info('---------------------------------- End !!!!! ----------------------------------', ['Sepparator'=>'---']);
    }

    public function is_buy() {
        return $this->side == 'Buy' ? 1:0;
    }

    public function get_opposite_trade_side() {
        return $this->side == "Buy" ? "Sell": "Buy";
    }

    public function get_ticker() {
        do {
            try {
                sleep(3);
                $ticker = $this->bitmex->getTicker($this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to submit", ['error'=>$e]);
            }
        } while ($ticker['last'] == null);
        return $ticker;
    }

    public function true_cancel_all_orders() {
        $result = False;
        $this->log->info("cancelling all open orders.", ["limit orders"=>$this->is_limit()]);
        sleep(1);
        do {
            try {
                $result = $this->bitmex->cancelAllOpenOrders($this->symbol);
                sleep(2);
            } catch (Exception $e) {
                $this->log->error("Failed to submit", ['error'=>$e]);
                break;
            }
        } while (!is_array($result));
        $this->log->info("open orders canceled.", ["limit orders"=>$this->is_limit()]);
    }

    public function true_cancel_order($orderId) {
        $result = False;
        $this->log->info("cancelling order.", ["order"=>$orderId]);
        sleep(1);
        do {
            try {
                $result = $this->bitmex->cancelOpenOrder($orderId);
                sleep(2);
            } catch (Exception $e) {
                $this->log->error("Failed to submit", ['error'=>$e]);
                break;
            }
        } while (!is_array($result));
        $this->log->info("open orders canceled.", ["limit orders"=>$this->is_limit()]);
    }

     public function true_edit($orderId, $price, $amount, $stopPx) {
        $result = False;
        $this->log->info("editing order.", ["orderId" => $orderId, "price"=>$price, "amount"=>$amount, "stop"=>$stopPx]);
        sleep(1);
        do {
            try {
                $result = $this->bitmex->editOrder($orderId, $price, $amount, $stopPx);
                sleep(2);
            } catch (Exception $e) {
                $this->log->error("Failed to submit", ['error'=>$e]);
                break;
            }

        } while (!is_array($result));
    }

    public function true_bulk_edit($orderIds, $prices, $amounts, $stopPxs) {
        $result = False;
        $this->log->info("editing orders.", ["price"=>$prices, "amounts"=>$amounts, "stop"=>$stopPxs]);
        sleep(1);
        do {
            try {
                $result = $this->bitmex->bulkEditOrders($orderIds, $prices, $amounts, $stopPxs);
                sleep(2);
            } catch (Exception $e) {
                $this->log->error("Failed to submit", ['error'=>$e]);
                break;
            }

        } while (!is_array($result));
    }

    public function true_create_order($type, $side, $amount, $price, $stopPx = null) {
        $this->log->info("Sending a Create Order command", ['side'=>$side.' '.$amount.' contracts, Price=>'.$price]);
        $order = False;
        sleep(2);
        do {
            try {
                $order = $this->bitmex->createOrder($this->symbol, $type, $side, $price, $amount, $stopPx);
            } catch (Exception $e) {
                if (strpos($e, 'Invalid orderQty') !== false) {
                    $this->log->error("Failed to submit, Invalid quantity", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'insufficient Available Balance') !== false) {
                    $this->log->error("Failed to submit, insufficient Available Balance", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'Invalid API Key') !== false) {
                    $this->log->error("Failed to submit, Invalid API Key", ['error'=>$e]);
                    return false;
                }
                $this->log->error("Failed to create/close position retrying in 2 seconds", ['error'=>$e]);
                sleep(1);
                continue;
            }
            $this->log->info("Position successful, OrderId:".$order['orderID'], ['price'=>$order['price'], 'amount'=>$amount, 'stop'=>$stopPx]);
            break;
        } while (1);
        return $order;
    }
    public function get_open_positions() {
        $openPositions = null;
        do {
            try {
                sleep(2);
                $openPositions = $this->bitmex->getOpenPositions();
            } catch (Exception $e) {
                $this->log->error("failed to submit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($openPositions));
        return $openPositions;
    }

    public function are_open_positions() {
        $openPositions = $this->get_open_positions();
        if(sizeof($openPositions) == 0) {
            return False;
        }
        foreach($openPositions as $pos) {
            if ($pos["symbol"] == $this->symbol) {
                return $pos;
            }
        }
        return False;
    }
    public function get_open_orders() {
        $openOrders = null;
        do {
            try {
                sleep(1);
                $openOrders = $this->bitmex->getOpenOrders($this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to submit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($openOrders));
        return $openOrders;
    }

    public function get_open_order_by_id($id) {
        $openOrders = $this->get_open_orders();
        foreach($openOrders as $order) {
            if($order["orderID"] == $id) {
                return $order;
            }
        }
        return False;
    }
    public function get_order_book() {
        $orderBook = null;
        do {
            try {
                $orderBook = $this->bitmex->getOrderBook(1, $this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to submit", ['error'=>$e]);
            }
        } while (!is_array($orderBook));
        return $orderBook;
    }

    public function get_limit_price($side) {
        $orderBook = $this->get_order_book();
        foreach ($orderBook as $book) {
            if ($book["side"] == $side) {
                return $book['price'];
            }
        }
        return False;
    }

    public function are_open_orders() {
        sleep(1);
        $openOrders = $this->get_open_orders();
        sleep(1);
        $return = empty($openOrders) ? False:$openOrders;
        return $return;
    }

    public function is_stop() {
        $openOrders= $this->get_open_orders();
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Stop") {
                return $order["orderID"];
            }
        }
        return False;
    }
    public function is_limit() {
        $openOrders = $this->get_open_orders();
        if (!is_array($openOrders)) {
            return False;
        }
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit") {
                return True;
            }
        }
        return False;
    }

    public function wait_on_limit_order() {
        $this->log->info("waiting on limit order to be accepted",["limit"=>$this->is_limit()]);
        do {
            sleep(3);
        } while($this->are_open_positions() === False);
        return True;
    }

    public function limit_open_or_close($side, $amount) {
        $lastLimitPrice = $this->get_limit_price($side);
        $this->log->info("limit ".$side. " proccess begin.",["starting price"=>$lastLimitPrice]);
        $order = $this->true_create_order('Limit', $side, $amount, $lastLimitPrice);
        sleep(4);
        if ($this->get_open_order_by_id($order["orderID"]) == False) {
            return;
        }
        do {
            $limitPrice = $this->get_limit_price($side);
            if ($lastLimitPrice != $limitPrice) {
                $lastLimitPrice = $limitPrice;
                $this->true_edit($order["orderID"], $limitPrice, null, null);
            }
            sleep(5);
        } while ($this->get_open_order_by_id($order["orderID"]) !== False);
        $this->log->info("Limit order was filled",["price"=>$lastLimitPrice]);
    }

    public function trade_open() {
        $this->limit_open_or_close($this->side, $this->initialAmount);
        sleep(2);
        $this->amount = abs($this->are_open_positions()['currentQty']);
        sleep(2);
        if ($stopOrder = $this->is_stop()) {
            $this->true_edit($stopOrder, null, $this->amount, $this->stopPx);
        } else {
            $this->true_create_order('Stop', $this->get_opposite_trade_side(), $this->amount, null, $this->stopPx);
        }
        return True;
    }

    public function take_profit() {
        $this->limit_open_or_close($this->side, $this->initialAmount);
        $this->amount = abs($this->are_open_positions()['currentQty']);
        if ($this->amount == 0) {
            $this->true_cancel_all_orders();
            return True;
        }
        if ($stopOrder = $this->is_stop()) {
            $this->true_edit($stopOrder, null, $this->amount, null);
            return True;
        } else {
            $this->log->error("StopLoss order was not found.",[$this->get_open_orders()]);
        }

        return False;
    }

    public function num_of_closing_orders() {
        sleep(1);
        $openOrders= $this->get_open_orders();
        sleep(1);
        $num = 0;
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit" and $order["side"] == $this->get_opposite_trade_side()) {
                $num += 1;
            }
        }
        return $num;
    }

    public function trade_manage() {
        $wallet = False;
        try {
            $wallet = $this->bitmex->getWallet();
        }  catch (Exception $e) {
            $this->log->error("Falied to et wallet.",[]);
        }
        $this->wait_on_limit_order();
        try {
            $pos = $this->bitmex->getPosition($this->symbol, 1);
        }  catch (Exception $e) {
            $this->log->error("Falied to get position.",[]);
        }
        $pnl = $pos[0]['realisedPnl'];
        $wallet = end($wallet);
        $walletAmout = $wallet['walletBalance'];
        $this->log->info("wallet has ".$walletAmout." btc in it", ["realisedPnl"=>$pnl]);

        $targetInfo = json_decode(file_get_contents($this->symbol."_manage_info.json"));
        $this->targets = json_decode(json_encode($targetInfo), true);

        $this->log->info("Targets are: ", ['targets'=>$this->targets]);
        $this->amount = 0;
        foreach ($this->targets as $target) {
            $order = $this->true_create_order('Limit', null, $target['amount'], $target['price']);
            $this->amount += $target['amount'];
        }
        $this->true_create_order('Stop', null, $this->amount, null, $this->stopPx);

        $stop = False;
        do {
            if ($stop !== False) {
                if ($this->num_of_closing_orders < sizeof($this->targets)) {
                    $stop = $this->are_open_positions()["avgEntryPrice"];
                }
            } else {
                try {
                    $candle = $this->bitmex->getCandles('1m', 1, $this->symbol)[0];
                } catch (Exception $e) {
                    $log->error("Failed retrieving ticker, sleeping few seconds", ['error'=>$e]);
                    continue;
                }
                if ($candle['close'] > $stop and $this->side == "Buy" or $candle['close'] < $stop and $this->side == 'Sell') {
                    $this->true_cancel_all_orders();
                    $this->limit_open_or_close($this->get_opposite_trade_side(), $this->amount);
                }
            }
            sleep(3);
        } while ($this->are_open_positions() !== False);
        $this->true_cancel_all_orders();

        try {
            $wallet = $this->bitmex->getWallet();
        } catch (Exception $e) {
            $this->log->error("Failed to get wallet.",[]);
        }
        $wallet = end($wallet);
        $currentWalletAmout = $wallet['walletBalance'];
        try {
            $pos = $this->bitmex->getPosition($this->symbol, 1);
        }  catch (Exception $e) {
            $this->log->error("Falied to get position.",[]);
        }

        $currentPnl = $pos[0]['realisedPnl'];
        $this->log->info("wallet has ".$currentWalletAmout." btc in it", ["previouswallet"=>$walletAmout]);
        $res = ($currentPnl-$pnl) < 0 ? "Loss":"Win";
        $this->log->info("Trade made ".($currentPnl-$pnl), ["result"=>$res]);
    }

    public function create_limit() {
        $order = $this->true_create_order('Limit', $this->side, $this->initialAmount, $this->stopPx);
    }
}

?>
