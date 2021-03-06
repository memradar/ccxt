<?php

namespace ccxt;

class livecoin extends Exchange {

    public function describe () {
        return array_replace_recursive (parent::describe (), array (
            'id' => 'livecoin',
            'name' => 'LiveCoin',
            'countries' => array ( 'US', 'UK', 'RU' ),
            'rateLimit' => 1000,
            'hasCORS' => false,
            'hasFetchTickers' => true,
            'urls' => array (
                'logo' => 'https://user-images.githubusercontent.com/1294454/27980768-f22fc424-638a-11e7-89c9-6010a54ff9be.jpg',
                'api' => 'https://api.livecoin.net',
                'www' => 'https://www.livecoin.net',
                'doc' => 'https://www.livecoin.net/api?lang=en',
            ),
            'api' => array (
                'public' => array (
                    'get' => array (
                        'exchange/all/order_book',
                        'exchange/last_trades',
                        'exchange/maxbid_minask',
                        'exchange/order_book',
                        'exchange/restrictions',
                        'exchange/ticker', // omit params to get all tickers at once
                        'info/coinInfo',
                    ),
                ),
                'private' => array (
                    'get' => array (
                        'exchange/client_orders',
                        'exchange/order',
                        'exchange/trades',
                        'exchange/commission',
                        'exchange/commissionCommonInfo',
                        'payment/balances',
                        'payment/balance',
                        'payment/get/address',
                        'payment/history/size',
                        'payment/history/transactions',
                    ),
                    'post' => array (
                        'exchange/buylimit',
                        'exchange/buymarket',
                        'exchange/cancellimit',
                        'exchange/selllimit',
                        'exchange/sellmarket',
                        'payment/out/capitalist',
                        'payment/out/card',
                        'payment/out/coin',
                        'payment/out/okpay',
                        'payment/out/payeer',
                        'payment/out/perfectmoney',
                        'payment/voucher/amount',
                        'payment/voucher/make',
                        'payment/voucher/redeem',
                    ),
                ),
            ),
            'fees' => array (
                'trading' => array (
                    'tierBased' => false,
                    'percentage' => true,
                    'maker' => 0.18 / 100,
                    'taker' => 0.18 / 100,
                ),
            ),
        ));
    }

    public function fetch_markets () {
        $markets = $this->publicGetExchangeTicker ();
        $restrictions = $this->publicGetExchangeRestrictions ();
        $restrictionsById = $this->index_by($restrictions['restrictions'], 'currencyPair');
        $result = array ();
        for ($p = 0; $p < count ($markets); $p++) {
            $market = $markets[$p];
            $id = $market['symbol'];
            $symbol = $id;
            list ($base, $quote) = explode ('/', $symbol);
            $coinRestrictions = $this->safe_value($restrictionsById, $symbol);
            $precision = array (
                'price' => 5,
                'amount' => 8,
                'cost' => 8,
            );
            $limits = array (
                'amount' => array (
                    'min' => pow (10, -$precision['amount']),
                    'max' => pow (10, $precision['amount']),
                ),
            );
            if ($coinRestrictions) {
                $precision['price'] = $this->safe_integer($coinRestrictions, 'priceScale', 5);
                $limits['amount']['min'] = $this->safe_float($coinRestrictions, 'minLimitQuantity', $limits['amount']['min']);
            }
            $limits['price'] = array (
                'min' => pow (10, -$precision['price']),
                'max' => pow (10, $precision['price']),
            );
            $result[] = array_merge ($this->fees['trading'], array (
                'id' => $id,
                'symbol' => $symbol,
                'base' => $base,
                'quote' => $quote,
                'precision' => $precision,
                'limits' => $limits,
                'info' => $market,
            ));
        }
        return $result;
    }

    public function fetch_balance ($params = array ()) {
        $this->load_markets();
        $balances = $this->privateGetPaymentBalances ();
        $result = array ( 'info' => $balances );
        for ($b = 0; $b < count ($balances); $b++) {
            $balance = $balances[$b];
            $currency = $balance['currency'];
            $account = null;
            if (is_array ($result) && array_key_exists ($currency, $result))
                $account = $result[$currency];
            else
                $account = $this->account ();
            if ($balance['type'] == 'total')
                $account['total'] = floatval ($balance['value']);
            if ($balance['type'] == 'available')
                $account['free'] = floatval ($balance['value']);
            if ($balance['type'] == 'trade')
                $account['used'] = floatval ($balance['value']);
            $result[$currency] = $account;
        }
        return $this->parse_balance($result);
    }

    public function fetch_fees ($params = array ()) {
        $this->load_markets();
        $commissionInfo = $this->privateGetExchangeCommissionCommonInfo ();
        $commission = $this->safe_float($commissionInfo, 'commission');
        return array (
            'info' => $commissionInfo,
            'maker' => $commission,
            'taker' => $commission,
            'withdraw' => 0.0,
        );
    }

    public function fetch_order_book ($symbol, $params = array ()) {
        $this->load_markets();
        $orderbook = $this->publicGetExchangeOrderBook (array_merge (array (
            'currencyPair' => $this->market_id($symbol),
            'groupByPrice' => 'false',
            'depth' => 100,
        ), $params));
        $timestamp = $orderbook['timestamp'];
        return $this->parse_order_book($orderbook, $timestamp);
    }

    public function parse_ticker ($ticker, $market = null) {
        $timestamp = $this->milliseconds ();
        $symbol = null;
        if ($market)
            $symbol = $market['symbol'];
        $vwap = floatval ($ticker['vwap']);
        $baseVolume = floatval ($ticker['volume']);
        $quoteVolume = $baseVolume * $vwap;
        return array (
            'symbol' => $symbol,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'high' => floatval ($ticker['high']),
            'low' => floatval ($ticker['low']),
            'bid' => floatval ($ticker['best_bid']),
            'ask' => floatval ($ticker['best_ask']),
            'vwap' => floatval ($ticker['vwap']),
            'open' => null,
            'close' => null,
            'first' => null,
            'last' => floatval ($ticker['last']),
            'change' => null,
            'percentage' => null,
            'average' => null,
            'baseVolume' => $baseVolume,
            'quoteVolume' => $quoteVolume,
            'info' => $ticker,
        );
    }

    public function fetch_tickers ($symbols = null, $params = array ()) {
        $this->load_markets();
        $response = $this->publicGetExchangeTicker ($params);
        $tickers = $this->index_by($response, 'symbol');
        $ids = array_keys ($tickers);
        $result = array ();
        for ($i = 0; $i < count ($ids); $i++) {
            $id = $ids[$i];
            $market = $this->markets_by_id[$id];
            $symbol = $market['symbol'];
            $ticker = $tickers[$id];
            $result[$symbol] = $this->parse_ticker($ticker, $market);
        }
        return $result;
    }

    public function fetch_ticker ($symbol, $params = array ()) {
        $this->load_markets();
        $market = $this->market ($symbol);
        $ticker = $this->publicGetExchangeTicker (array_merge (array (
            'currencyPair' => $market['id'],
        ), $params));
        return $this->parse_ticker($ticker, $market);
    }

    public function parse_trade ($trade, $market) {
        $timestamp = $trade['time'] * 1000;
        return array (
            'info' => $trade,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'symbol' => $market['symbol'],
            'id' => (string) $trade['id'],
            'order' => null,
            'type' => null,
            'side' => strtolower ($trade['type']),
            'price' => $trade['price'],
            'amount' => $trade['quantity'],
        );
    }

    public function fetch_trades ($symbol, $since = null, $limit = null, $params = array ()) {
        $this->load_markets();
        $market = $this->market ($symbol);
        $response = $this->publicGetExchangeLastTrades (array_merge (array (
            'currencyPair' => $market['id'],
        ), $params));
        return $this->parse_trades($response, $market, $since, $limit);
    }

    public function parse_order ($order, $market = null) {
        $timestamp = $this->safe_integer($order, 'lastModificationTime');
        if (!$timestamp)
            $timestamp = $this->parse8601 ($order['lastModificationTime']);
        $trades = null;
        if (is_array ($order) && array_key_exists ('trades', $order))
            // TODO currently not supported by livecoin
            // $trades = $this->parse_trades($order['trades'], $market, since, limit);
            $trades = null;
        $status = null;
        if ($order['orderStatus'] == 'OPEN' || $order['orderStatus'] == 'PARTIALLY_FILLED') {
            $status = 'open';
        } else if ($order['orderStatus'] == 'EXECUTED' || $order['orderStatus'] == 'PARTIALLY_FILLED_AND_CANCELLED') {
            $status = 'closed';
        } else {
            $status = 'canceled';
        }
        $symbol = $order['currencyPair'];
        list ($base, $quote) = explode ('/', $symbol);
        $type = null;
        $side = null;
        if (mb_strpos ($order['type'], 'MARKET') !== false) {
            $type = 'market';
        } else {
            $type = 'limit';
        }
        if (mb_strpos ($order['type'], 'SELL') !== false) {
            $side = 'sell';
        } else {
            $side = 'buy';
        }
        $price = $this->safe_float($order, 'price', 0.0);
        $cost = $this->safe_float($order, 'commissionByTrade', 0.0);
        $remaining = $this->safe_float($order, 'remainingQuantity', 0.0);
        $amount = $this->safe_float($order, 'quantity', $remaining);
        $filled = $amount - $remaining;
        return array (
            'info' => $order,
            'id' => $order['id'],
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'status' => $status,
            'symbol' => $symbol,
            'type' => $type,
            'side' => $side,
            'price' => $price,
            'cost' => $cost,
            'amount' => $amount,
            'filled' => $filled,
            'remaining' => $remaining,
            'trades' => $trades,
            'fee' => array (
                'cost' => $cost,
                'currency' => $quote,
            ),
        );
    }

    public function fetch_orders ($symbol = null, $since = null, $limit = null, $params = array ()) {
        $this->load_markets();
        $market = null;
        if ($symbol)
            $market = $this->market ($symbol);
        $pair = $market ? $market['id'] : null;
        $request = array ();
        if ($pair)
            $request['currencyPair'] = $pair;
        if ($since)
            $request['issuedFrom'] = intval ($since);
        if ($limit)
            $request['endRow'] = $limit - 1;
        $response = $this->privateGetExchangeClientOrders (array_merge ($request, $params));
        $result = array ();
        $rawOrders = array ();
        if ($response['data'])
            $rawOrders = $response['data'];
        for ($i = 0; $i < count ($rawOrders); $i++) {
            $order = $rawOrders[$i];
            $result[] = $this->parse_order($order, $market);
        }
        return $result;
    }

    public function fetch_open_orders ($symbol = null, $since = null, $limit = null, $params = array ()) {
        $result = $this->fetch_orders($symbol, $since, $limit, array_merge (array (
            'openClosed' => 'OPEN',
        ), $params));
        return $result;
    }

    public function fetch_closed_orders ($symbol = null, $since = null, $limit = null, $params = array ()) {
        $result = $this->fetch_orders($symbol, $since, $limit, array_merge (array (
            'openClosed' => 'CLOSED',
        ), $params));
        return $result;
    }

    public function create_order ($symbol, $type, $side, $amount, $price = null, $params = array ()) {
        $this->load_markets();
        $method = 'privatePostExchange' . $this->capitalize ($side) . $type;
        $market = $this->market ($symbol);
        $order = array (
            'quantity' => $this->amount_to_precision($symbol, $amount),
            'currencyPair' => $market['id'],
        );
        if ($type == 'limit')
            $order['price'] = $this->price_to_precision($symbol, $price);
        $response = $this->$method (array_merge ($order, $params));
        return array (
            'info' => $response,
            'id' => (string) $response['orderId'],
        );
    }

    public function cancel_order ($id, $symbol = null, $params = array ()) {
        if (!$symbol)
            throw new ExchangeError ($this->id . ' cancelOrder requires a $symbol argument');
        $this->load_markets();
        $market = $this->market ($symbol);
        $currencyPair = $market['id'];
        $response = $this->privatePostExchangeCancellimit (array_merge (array (
            'orderId' => $id,
            'currencyPair' => $currencyPair,
        ), $params));
        $message = $this->safe_string($response, 'message', $this->json ($response));
        if (is_array ($response) && array_key_exists ('success', $response)) {
            if (!$response['success']) {
                throw new InvalidOrder ($message);
            } else if (is_array ($response) && array_key_exists ('cancelled', $response)) {
                if ($response['cancelled']) {
                    return $response;
                } else {
                    throw new OrderNotFound ($message);
                }
            }
        }
        throw new ExchangeError ($this->id . ' cancelOrder() failed => ' . $this->json ($response));
    }

    public function fetch_deposit_address ($currency, $params = array ()) {
        $request = array (
            'currency' => $currency,
        );
        $response = $this->privateGetPaymentGetAddress (array_merge ($request, $params));
        $address = $this->safe_string($response, 'wallet');
        return array (
            'currency' => $currency,
            'address' => $address,
            'status' => 'ok',
            'info' => $response,
        );
    }

    public function sign ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $url = $this->urls['api'] . '/' . $path;
        $query = $this->urlencode ($this->keysort ($params));
        if ($method == 'GET') {
            if ($params) {
                $url .= '?' . $query;
            }
        }
        if ($api == 'private') {
            $this->check_required_credentials();
            if ($method == 'POST')
                $body = $query;
            $signature = $this->hmac ($this->encode ($query), $this->encode ($this->secret), 'sha256');
            $headers = array (
                'Api-Key' => $this->apiKey,
                'Sign' => strtoupper ($signature),
                'Content-Type' => 'application/x-www-form-urlencoded',
            );
        }
        return array ( 'url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers );
    }

    public function handle_errors ($code, $reason, $url, $method, $headers, $body) {
        if ($code >= 300) {
            if ($body[0] == "{") {
                $response = json_decode ($body, $as_associative_array = true);
                if (is_array ($response) && array_key_exists ('errorCode', $response)) {
                    $error = $response['errorCode'];
                    if ($error == 1) {
                        throw new ExchangeError ($this->id . ' ' . $this->json ($response));
                    } else if ($error == 2) {
                        if (is_array ($response) && array_key_exists ('errorMessage', $response)) {
                            if ($response['errorMessage'] == 'User not found')
                                throw new AuthenticationError ($this->id . ' ' . $response['errorMessage']);
                        } else {
                            throw new ExchangeError ($this->id . ' ' . $this->json ($response));
                        }
                    } else if (($error == 10) || ($error == 11) || ($error == 12) || ($error == 20) || ($error == 30) || ($error == 101) || ($error == 102)) {
                        throw new AuthenticationError ($this->id . ' ' . $this->json ($response));
                    } else if ($error == 31) {
                        throw new NotSupported ($this->id . ' ' . $this->json ($response));
                    } else if ($error == 32) {
                        throw new ExchangeError ($this->id . ' ' . $this->json ($response));
                    } else if ($error == 100) {
                        throw new ExchangeError ($this->id . ' => Invalid parameters ' . $this->json ($response));
                    } else if ($error == 103) {
                        throw new InvalidOrder ($this->id . ' => Invalid currency ' . $this->json ($response));
                    } else if ($error == 104) {
                        throw new InvalidOrder ($this->id . ' => Invalid amount ' . $this->json ($response));
                    } else if ($error == 105) {
                        throw new InvalidOrder ($this->id . ' => Unable to block funds ' . $this->json ($response));
                    } else {
                        throw new ExchangeError ($this->id . ' ' . $this->json ($response));
                    }
                }
            }
            throw new ExchangeError ($this->id . ' ' . $body);
        }
    }

    public function request ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $response = $this->fetch2 ($path, $api, $method, $params, $headers, $body);
        if (is_array ($response) && array_key_exists ('success', $response)) {
            if (!$response['success']) {
                throw new ExchangeError ($this->id . ' error => ' . $this->json ($response));
            }
        }
        return $response;
    }
}
