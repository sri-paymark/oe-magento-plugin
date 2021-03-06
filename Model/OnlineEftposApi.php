<?php

namespace Paymark\PaymarkOE\Model;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManager;
use Paymark\PaymarkOE\Exception\ApiConflictException;
use Zend\Http\Client;
use Zend\Http\Request;

class OnlineEftposApi
{

    /**
     * @var \Paymark\PaymarkOE\Helper\Helper
     */
    private $_helper;

    /**
     * @var boolean
     */
    private $_prod = false;

    /**
     * @var string
     */
    private $_prodUrl = 'https://api.paymark.nz/';

    /**
     * @var string
     */
    private $_devUrl = 'https://apitest.uat.paymark.nz/';

    /**
     * @var string
     */
    private $_sandboxUrl = 'https://apitest.paymark.nz/';

    /**
     * @var \Zend\Http\Client
     */
    private $_client;

    /**
     * @var string
     */
    private $_merchantId;

    /**
     * @var string
     */
    private $_consumerKey;

    /**
     * @var string
     */
    private $_consumerSecret;

    /**
     * @var int
     */
    private $_statusCode;

    /**
     * @var int
     */
    private $_bearer;

    /**
     * @var EncryptorInterface
     */
    protected $_encryptor;

    /**
     * @var StoreManager
     */
    protected $_storeManager;

    const TYPE_REGULAR = 'REGULAR';

    const TYPE_TRUSTSETUP = 'TRUSTSETUP';

    const TYPE_TRUSTED = 'TRUSTED';

    /**
     * OnlineEftposApi constructor.
     * @param Client $zendClient
     * @param EncryptorInterface $encryptor
     * @param StoreManager $storeManager
     */
    public function __construct(
        Client $zendClient,
        EncryptorInterface $encryptor,
        StoreManager $storeManager
    )
    {
        $this->_encryptor = $encryptor;

        $this->_storeManager = $storeManager;

        $this->_client = $zendClient;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $this->_helper = $objectManager->create("\Paymark\PaymarkOE\Helper\Helper");

        $this->_prod = $this->_helper->isProdMode();

        $this->_merchantId = $this->_helper->getConfig('merchant_id');

        $this->_consumerKey = $this->_encryptor->decrypt($this->_helper->getConfig('consumer_key'));

        $this->_consumerSecret = $this->_encryptor->decrypt($this->_helper->getConfig('consumer_secret'));
    }

    /**
     * Login to the Online Eftpos API and store the access token
     *
     * @return mixed
     * @throws \Exception
     */
    public function login()
    {
        $auth = $this->call(Request::METHOD_POST, 'bearer', [], true);

        $this->_bearer = $auth->access_token;

        return $auth;
    }

    /**
     * Generate payment request with Paymark OE
     *
     * @param $orderId
     * @param $value
     * @param $currency
     * @param $payerId
     * @param $bankId
     * @param $description
     * @param $merchantUrl
     * @param $callbackUrl
     * @param $type
     * @param string $payerType
     * @return mixed
     * @throws \Exception
     */
    public function createTransaction($orderId, $value, $currency, $payerId, $bankId, $description, $merchantUrl, $callbackUrl, $type = self::TYPE_REGULAR, $payerType = 'MOBILE')
    {
        $params = [
            'bank' => [
                'payerId' => $payerId,
                'bankId' => $bankId,
                'payerIdType' => $payerType
            ],
            'merchant' => [
                'merchantIdCode' => $this->_merchantId,
                'merchantUrl' => $merchantUrl,
                'callbackUrl' => $callbackUrl
            ],
            'transaction' => [
                'amount' => $value,
                'transactionType' => $type,
                'currency' => $currency,
                'description' => $description,
                'orderId' => $orderId,
                'userAgent' => $_SERVER['HTTP_USER_AGENT'],
                'userIpAddress' => $this->getClientIP()
            ]
        ];

        return $this->call(Request::METHOD_POST, 'transaction/oepayment/', $params);
    }

    /**
     * Get transaction details from transaction id
     *
     * @param $transactionId
     * @return mixed
     * @throws \Exception
     */
    public function getTransaction($transactionId)
    {
        return $this->call(Request::METHOD_GET, 'transaction/oepayment/' . $transactionId);
    }

    /**
     * Delete autopay contract at Paymark
     *
     * @param $autopayId
     * @return mixed
     * @throws \Exception
     */
    public function deleteAutopayContract($autopayId)
    {
        return $this->call(Request::METHOD_PUT, 'oemerchanttrust/' . $autopayId, [
            'status' => 'CANCELLED'
        ]);
    }

    /**
     * Call remote API
     *
     * @param $method
     * @param null $uri
     * @param array $params
     * @param bool $authorise
     * @return mixed
     * @throws \Exception
     */
    public function call($method, $uri = null, array $params = [], $authorise = false)
    {
        $this->_client->reset();

        $params = $this->getOptions($params);

        $baseUrl = $this->_prod ? $this->_prodUrl : $this->_sandboxUrl;

        try {

            if($authorise) {
                $this->_client->setAuth($this->_consumerKey, $this->_consumerSecret);

                $params['grant_type'] = 'client_credentials';
                $this->_client->setParameterPost($params);
            } else {
                $contentType = 'application/vnd.paymark_api+json';

                $this->_client->setHeaders([
                    'Authorization' => 'Bearer ' . $this->_bearer,
                    'Accept' => $contentType,
                    'Content-Type' => $contentType
                ]);

                if($method != Request::METHOD_GET) {
                    // data needs to be sent raw due to custom content-type header
                    $this->_client->setRawBody(json_encode($params));
                }
            }

            $this->_helper->log(json_encode($params));

            $this->_client->setUri($baseUrl . $uri);

            $this->_client->setMethod($method);

            $this->_client->send();

            $response = $this->_client->getResponse();

        } catch (\Zend\Http\Exception\RuntimeException $e) {
            $this->_helper->log('Zend client error: ' . $e->getMessage());
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            $this->_helper->log('Zend client error: ' . $e->getMessage());
            throw new \Exception($e->getMessage());
        }

        // parse body
        $responseData = json_decode($response->getBody());

        // enable to log full response body
        //$this->_helper->log('status:' . $response->getStatusCode());
        //$this->_helper->log($response->getBody());

        // http error code
        $this->setStatusCode($response->getStatusCode());

        switch ($response->getStatusCode()) {
            case 200:
            case 201:
            case 204:
                return $responseData;

            case 409:
                $message = !empty($responseData->error) ? $responseData->error : 'Conflict error while processing';
                throw new ApiConflictException($message, $response->getStatusCode());

            default:
                if(!empty($responseData->error_description)) {
                    $message = '[' . $responseData->error . '] ' . $responseData->error_description;
                } else {
                    $message = '[error] ' . $responseData->error;
                }

                throw new \Exception($message, $response->getStatusCode());
        }
    }
    /**
     * @param array $params
     *
     * @return array
     */
    private function getOptions(array $params = [])
    {
        $params = array_merge([], $params);

        return $params;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->_statusCode;
    }

    /**
     * @param int $statusCode
     */
    public function setStatusCode($statusCode)
    {
        $this->_statusCode = $statusCode;
    }

    /**
     * Find user IP address
     *
     * @return string
     */
    public function getClientIP()
    {
        $ipaddress = '';

        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($keys as $k) {
            if (isset($_SERVER[$k]) && !empty($_SERVER[$k]) && filter_var($_SERVER[$k], FILTER_VALIDATE_IP)) {
                $ipaddress = $_SERVER[$k];
                break;
            }
        }

        return $ipaddress;
    }
}
