<?php

namespace Binance;

use Web3p\EthereumTx\Transaction;

class Bnb
{
    protected $proxyApi;

    function __construct(ProxyApi $proxyApi)
    {
        $this->proxyApi = $proxyApi;
    }

    function __call($name, $arguments)
    {
        return call_user_func_array([$this->proxyApi, $name], $arguments);
    }

    
    /**
     * Retrieves the current gas price in mwei based on the given type.
     * 
     * @param string $type Type of gas price to retrieve. Can be 'rapid', 'fast', or 'standard'. Defaults to 'standard'.
     * @param string $apiKey Etherscan API key to use for gas price estimation
     * @return string Current gas price in mwei
     */
    public static function gasPriceOracle($type = 'standard', $apiKey): string
    {
        $url = 'https://api.etherscan.io/v2/api?chainid=56&module=gastracker&action=gasoracle&apikey='.$apiKey;
        $res = Utils::httpRequest('GET', $url);
        
        if (isset($res['status']) && $res['status'] == '1' && isset($res['result'])) {
            $gasPriceGwei = match($type) {
                'rapid' => $res['result']['FastGasPrice'] ?? $res['result']['ProposeGasPrice'],
                'fast' => $res['result']['ProposeGasPrice'] ?? $res['result']['SafeGasPrice'],
                'standard' => $res['result']['SafeGasPrice'] ?? $res['result']['ProposeGasPrice'],
                default => $res['result']['ProposeGasPrice']
            };
            
            $gasPriceMwei = (float)$gasPriceGwei * 1000;
            $price = Utils::toWei((string)$gasPriceMwei, 'mwei');
            return (string)$price;
        }
        
        // Fallback to 0.05 gwei if status is 0 or API fails
        $fallbackPrice = Utils::toWei('50', 'mwei');
        return (string)$fallbackPrice;
    }

    public static function getChainId($network): int
    {
        $chainId = 56;
        switch ($network) {
            case 'mainnet':
                $chainId = 56;
                break;
            case 'testnet':
                $chainId = 97;
                break;
            default:
                break;
        }

        return $chainId;
    }

    /**
     * Transfers BNB to a given address.
     * 
     * @param string $privateKey Private key of the sender.
     * @param string $to Address to transfer BNB to.
     * @param float $value Amount of BNB to transfer, in ether.
     * @param string $apiKey Etherscan API key to use for gas price estimation
     * @param string $gasPrice Gas price to use for the transaction, in gwei. Can be 'rapid', 'fast', or 'standard'. Defaults to 'standard'.
     * @return array Response from the proxy API.
     */
    public function transfer(string $privateKey, string $to, float $value, string $apiKey, string $gasPrice = 'standard')
    {
        $from = PEMHelper::privateKeyToAddress($privateKey);
        $nonce = $this->proxyApi->getNonce($from);
        if (!Utils::isHex($gasPrice)) {
            $gasPrice = Utils::toHex(self::gasPriceOracle($gasPrice, $apiKey), true);
        }

        $eth = Utils::toWei("$value", 'ether');
        //        $eth = $value * 1e16;
        $eth = Utils::toHex($eth, true);

        $transaction = new Transaction([
            'nonce' => "$nonce",
            'from' => $from,
            'to' => $to,
            'gas' => '0x76c0',
            'gasPrice' => "$gasPrice",
            'value' => "$eth",
            'chainId' => self::getChainId($this->proxyApi->getNetwork()),
        ]);

        $raw = $transaction->sign($privateKey);
        $res = $this->proxyApi->sendRawTransaction('0x' . $raw);
        return $res;
    }
}
