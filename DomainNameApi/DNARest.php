<?php
/**
 * Created by PhpStorm.
 * User: bunyaminakcay
 * Project name whmcs-dna
 * 20.11.2022 00:13
 * Bünyamin AKÇAY <bunyamin@bunyam.in>
 */

namespace DomainNameApi;

require_once __DIR__ . '/SharedApiConfigAndUtilsTrait.php';

use Exception;

class DNARest
{
    use SharedApiConfigAndUtilsTrait;

    /**
     * Error reporting enabled
     */
    private bool $errorReportingEnabled = true;
    /**
     * Error Reporting Will send this sentry endpoint, if errorReportingEnabled is true
     * This request does not include sensitive informations, sensitive informations are filtered.
     * @var string $errorReportingDsn
     */
    private string $errorReportingDsn  = '';
    private string $errorReportingPath = '';

    /**
     * Api Username
     *
     * @var string $serviceUsername
     */
    private string $serviceUsername = "ownername";

    /*
     * Api Password
     * @var string $servicePassword
     */
    private string $servicePassword = "ownerpass";

    /**
     * Api Service REST URL
     * @var string $serviceUrl
     */
    private string $serviceUrl          = "https://rest-test.domainnameapi.com/api/v1";
    private string $application         = "CORE";
    public array   $lastRequest         = [];
    public array   $lastResponse        = [];
    public ?array  $lastResponseHeaders = [];
    public ?array  $lastParsedResponse  = [];
    public string  $lastFunction        = '';
    private        $startAt;

    private $token;
    private $tokenExpire;
    private $authenticated = false;
    private $resellerId;

    /**
     * DNARest constructor.
     * Token-based authentication mode:
     * - ($resellerIdUUID, $token) -> use provided API key directly
     *
     * @param string $resellerId
     * @param string $token
     * @throws Exception
     */
    public function __construct($resellerId, $token)
    {
        $this->startAt = microtime(true);
        $this->_setApplication(__FILE__);

        $this->resellerId = $resellerId;
        $this->token      = $token;
    }


    /**
     * Get last request sent to API
     *
     * @return array Request data
     */
    public function getRequestData(): array
    {
        return $this->lastRequest;
    }

    /**
     * Get last response from API
     *
     * @return array Response data
     */
    public function getResponseData(): array
    {
        return $this->lastResponse;
    }

    /**
     * Set last request sent to API
     *
     * @param array $request Request data to set
     * @return DNARest
     */
    public function setRequestData($request)
    {
        $this->lastRequest = $request;
        return $this;
    }

    /**
     * Set last response from API
     *
     * @param array $response Response data to set
     * @return DNARest
     */
    public function setResponseData($response)
    {
        $this->lastResponse = $response;
        return $this;
    }

    /**
     * Get last response headers from API
     *
     * @return ?array Response headers
     */
    public function getResponseHeaders()
    {
        return $this->lastResponseHeaders;
    }

    /**
     * Set last response headers from API
     *
     * @param ?array $headers Response headers to set
     * @return DNARest
     */
    public function setResponseHeaders($headers)
    {
        $this->lastResponseHeaders = $headers;
        return $this;
    }

    /**
     * Get last parsed response from API
     *
     * @return ?array Parsed response data
     */
    public function getParsedResponseData()
    {
        return $this->lastParsedResponse;
    }

    /**
     * Set last parsed response from API
     *
     * @param ?array $response Parsed response data to set
     * @return DNARest
     */
    public function setParsedResponseData($response)
    {
        $this->lastParsedResponse = $response;
        return $this;
    }

    /**
     * Get last function called
     *
     * @return string Function name
     */
    public function getLastFunction()
    {
        return $this->lastFunction;
    }

    /**
     * Set last function called
     *
     * @param string $function Function name to set
     * @return DNARest
     */
    public function setLastFunction($function)
    {
        $this->lastFunction = $function;
        return $this;
    }

    /**
     * Get API service URL
     *
     * @return string Service URL
     */
    public function getServiceUrl()
    {
        return $this->serviceUrl;
    }

    /**
     * Set API service URL
     *
     * @param string $url New service URL
     */
    public function setServiceUrl($url)
    {
        $this->serviceUrl = $url;
    }


    private function get(string $url, array $params = [])
    {
        return $this->request('GET', $url, $params);
    }

    private function post(string $url, array $params = [])
    {
        return $this->request('POST', $url, $params);
    }

    private function put(string $url, array $params = [])
    {
        return $this->request('PUT', $url, $params);
    }

    private function delete(string $url, array $params = [])
    {
        return $this->request('DELETE', $url, $params);
    }


    /**
     * Make API request
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function request($method, $endpoint, $data = [])
    {
        $parsedResponse     = [];
        $this->lastFunction = __FUNCTION__ . ':' . $method . ' ' . $endpoint;

        $url = $this->serviceUrl . '/' . ltrim($endpoint, '/');

        $payloadForLog     = $data;
        $this->lastRequest = ['url' => $url, 'method' => $method, 'payload' => $payloadForLog];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$DEFAULT_TIMEOUT);

        //ignore ssl
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $this->token,  // Swagger'da X-API-KEY kullanılıyor
            '__reseller: ' . $this->resellerId  // Zorunlu header
        ];

        if (in_array($method, ['GET', 'DELETE'])) {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response_body             = curl_exec($ch);
        $response_status           = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastResponseHeaders = curl_getinfo($ch);
        $this->lastResponse        = json_decode($response_body, true) ?? ['raw_response' => $response_body];


        // Debug output removed to avoid breaking consumers

        if (curl_errno($ch)) {
            $error = new Exception('Curl error during request: ' . curl_error($ch));
            $this->sendErrorToSentryAsync($error);
        } else {
            curl_close($ch);


            if ($response_status >= 200 && $response_status <= 299) {
                $parsedResponse           = json_decode($response_body, true);
                $this->lastParsedResponse = $parsedResponse;

                if (method_exists($this, 'sendPerformanceMetricsToSentry')) {
                    $duration = (microtime(true) - $this->startAt) * 1000;
                    $this->sendPerformanceMetricsToSentry([
                        'operation'       => $this->lastFunction,
                        'duration'        => floatval($duration),
                        'success'         => true,
                        'timestamp'       => gmdate('Y-m-d\TH:i:s.', time()) . sprintf('%03d',
                                round(fmod(microtime(true), 1) * 1000)) . 'Z',
                        'start_timestamp' => gmdate('Y-m-d\TH:i:s.', (int)$this->startAt) . sprintf('%03d',
                                round(fmod($this->startAt, 1) * 1000)) . 'Z'
                    ]);
                }
            } else {
                $parsedResponse           = json_decode($response_body, true);
                $errorMessage             = $parsedResponse['message'] ?? ($parsedResponse['error']['message'] ?? $response_body);
                $errorCode                = $parsedResponse['code'] ?? ($parsedResponse['error']['code'] ?? 'HTTP_' . $response_status);
                $this->lastParsedResponse = $this->setError($errorCode, $errorMessage, $response_body);
                $error                    = new Exception($errorMessage, $response_status);

                $this->sendErrorToSentryAsync($error);
                throw $error;
            }
        }

        return $parsedResponse;
    }

    /**
     * Get Current account details with balance
     * @return array
     */
    public function getResellerDetails()
    {
        try {
            $response = $this->request('GET', 'deposit/accounts/me');

            // SOAP ile aynı pattern'i kullan
            $resp = [];

            if (isset($response['resellerId'])) {
                $resp['result'] = self::RESULT_OK;
                $resp['id']     = $response['resellerId'];
                $resp['name']   = $response['resellerName'] ?? '';
                $resp['active'] = true; // API'den status gelmiyor, varsayılan true

                // Ana para birimi USD, ikincil TRY
                $resp['balance']  = $response['usdBalance'] ?? 0;
                $resp['currency'] = 'USD';
                $resp['symbol']   = '$';

                // Balances array'i
                $balances         = [
                    [
                        'balance'  => $response['usdBalance'] ?? 0,
                        'currency' => 'USD',
                        'symbol'   => '$'
                    ],
                    [
                        'balance'  => $response['tryBalance'] ?? 0,
                        'currency' => 'TRY',
                        'symbol'   => '₺'
                    ]
                ];
                $resp['balances'] = $balances;

                // SOAP ile uyum için data anahtarı ekle
                $resp['data'] = $resp;
            } else {
                $resp['result'] = self::RESULT_ERROR;
                $resp['error']  = $this->setError('CREDENTIALS', 'Invalid response format',
                    'Response does not contain required fields');
            }

            return $resp;
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'RESELLER_DETAILS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get Current primary Balance for your account
     * @param string $currencyId
     * @return array
     */
    public function getCurrentBalance($currencyId = 'USD')
    {
        try {
            $response = $this->request('GET', 'deposit/accounts/me', ['currency' => strtoupper($currencyId)]);

            $balanceKey   = strtolower($currencyId) . 'Balance';
            $currencyName = strtoupper($currencyId);
            switch ($currencyName) {
                case 'USD':
                    $currencySymbol = '$';
                    break;
                case 'TRY':
                    $currencySymbol = '₺';
                    break;
                case 'EUR':
                    $currencySymbol = '€';
                    break;
                case 'GBP':
                    $currencySymbol = '£';
                    break;
                default:
                    $currencySymbol = '';
                    break;
            }
            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'ErrorCode'        => 0,
                    'OperationMessage' => 'Command completed succesfully.',
                    'OperationResult'  => 'SUCCESS',
                    'Balance'          => number_format($response[$balanceKey] ?? 0, 2, '.', ''),
                    'CurrencyId'       => 2,
                    'CurrencyInfo'     => null,
                    'CurrencyName'     => $currencyName,
                    'CurrencySymbol'   => $currencySymbol
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'BALANCE', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Checks availability of domain names with given extensions
     * @param array $domains
     * @param array $extensions
     * @param int $period
     * @param string $Command
     * @return array
     */
    public function checkAvailability($domains, $extensions, $period, $command = 'create')
    {
        try {
            $queries = [];
            foreach ($domains as $domain) {
                foreach ($extensions as $tld) {
                    $queries[] = ['domainName' => $domain . '.' . ltrim($tld, '.')];
                }
            }

            $response = $this->request('POST', 'domains/bulk-search', $queries);

            $availabilityData = [];
            if (isset($response) && is_array($response)) {
                foreach ($response as $item) {
                    $tld                = $item['info']['tld'] ?? substr(strrchr($item['info']['domainName'], "."), 1);
                    $availabilityData[] = [
                        "TLD"        => strtolower($tld),
                        "DomainName" => str_replace("." . strtolower($tld), '', $item['info']['domainName']),
                        "Status"     => $item['info']['available'] ? 'Available' : 'NotAvailable',
                        "Command"    => $Command,
                        "Period"     => $item['info']['period'] ?? $period,
                        "IsFee"      => $item['info']['isFee'] ?? false,
                        "Price"      => $item['info']['price'] ?? null,
                        "Currency"   => $item['info']['currency'] ?? null,
                        "Reason"     => $item['info']['reason'] ?? ($item['info']['available'] ? '' : 'Domain is not available'),
                    ];
                }
            }

            return [
                'result' => self::RESULT_OK,
                'data'   => $availabilityData
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'AVAILABILITY', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString()),
                'sl'     => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Get list of domains in your account
     * @param array $extra_parameters
     * @return array
     */
    public function getList($extra_parameters = [])
    {
        try {
            $defaults = ['MaxResultCount' => 200, 'SkipCount' => 0];
            $params   = array_merge($defaults, $extra_parameters);
            $response = $this->request('GET', 'domains', $params);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'Domains'    => isset($response['items']) ? array_map(function ($item) {
                        return [
                            'ID'                => $item['domainName'] ?? '',
                            'Status'            => (int)($item['status'] ?? 0),
                            'DomainName'        => $item['domainName'] ?? '',
                            'AuthCode'          => '',
                            'LockStatus'        => $item['lockStatus'] ?? false,
                            'a'                 => false,
                            'IsChildNameServer' => false,
                            'Contacts'          => [
                                'Billing'        => ['ID' => ''],
                                'Technical'      => ['ID' => ''],
                                'Administrative' => ['ID' => ''],
                                'Registrant'     => ['ID' => '']
                            ],
                            'Dates'             => [
                                'Start'         => isset($item['startDate']) ? date('Y-m-d\TH:i:s',
                                    strtotime($item['startDate'])) : '',
                                'Expiration'    => isset($item['expirationDate']) ? date('Y-m-d\TH:i:s',
                                    strtotime($item['expirationDate'])) : '',
                                'RemainingDays' => $item['remainingDay'] ?? 0,
                            ],
                            'NameServers'       => [],
                            'Additional'        => [],
                            'ChildNameServers'  => []
                        ];
                    }, $response['items']) : [],
                    'TotalCount' => (int)($response['totalCount'] ?? 0),
                    'Page'       => 1,
                    'PageSize'   => (int)($params['MaxResultCount'])
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'DOMAIN_LIST', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get TLD list and pricing matrix
     * @param int $count
     * @return array
     */
    public function getTldList($count = 20)
    {
        try {
            $response = $this->get('products/tlds', ['pageSize' => $count]);

            $tldData   = [];
            $idCounter = 1;
            if (isset($response['items']) && is_array($response['items'])) {
                foreach ($response['items'] as $tld) {
                    $pricing    = [];
                    $currencies = [];

                    // Fiyatlar
                    if (isset($tld['prices'][0]) && is_array($tld['prices'][0])) {
                        $priceTypes = [
                            'register'  => 'registration',
                            'renew'     => 'renew',
                            'transfer'  => 'transfer',
                            'restore'   => 'restore',
                            'refund'    => 'refund',
                            'backorder' => 'backorder'
                        ];
                        foreach ($priceTypes as $apiType => $outType) {
                            if (isset($tld['prices'][0][$apiType])) {
                                $apiValue = $tld['prices'][0][$apiType];
                                if (is_array($apiValue) && isset($apiValue[0])) {
                                    // Dizi ise
                                    foreach ($apiValue as $priceInfo) {
                                        if (is_array($priceInfo)) {
                                            $period                     = $priceInfo['period'] ?? 1;
                                            $price                      = isset($priceInfo['price']) ? number_format((float)$priceInfo['price'],
                                                4, '.', '') : '0.0000';
                                            $pricing[$outType][$period] = $price;
                                            $currencies[$outType]       = $priceInfo['currency'] ?? '';
                                        }
                                    }
                                } elseif (is_array($apiValue)) {
                                    // Obje ise
                                    $period                     = $apiValue['period'] ?? 1;
                                    $price                      = isset($apiValue['price']) ? number_format((float)$apiValue['price'],
                                        4, '.', '') : '0.0000';
                                    $pricing[$outType][$period] = $price;
                                    $currencies[$outType]       = $apiValue['currency'] ?? '';
                                }
                            }
                        }
                    }

                    $tldData[] = [
                        'id'               => $idCounter++,
                        'status'           => $tld['status'] ?? 'Active',
                        'maxchar'          => $tld['constraints']['maxLenght'] ?? 63,
                        'maxperiod'        => $tld['maxRegistrationPeriod'] ?? 10,
                        'minchar'          => $tld['constraints']['minLength'] ?? 1,
                        'minperiod'        => $tld['minRegistrationPeriod'] ?? 1,
                        'tld'              => $tld['name'],
                        'gracePeriod'      => $tld['addGracePeriod'] == 1,
                        'redemptionPeriod' => $tld['failurePeriod'] == 1,
                        'pricing'          => $pricing,
                        'currencies'       => $currencies
                    ];
                }
            }

            return [
                'result' => self::RESULT_OK,
                'data'   => $tldData
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'TLD_LIST', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get detailed information for a domain
     * @param string $domainName
     * @return array
     */
    public function getDetails($domainName)
    {
        try {
            $response = $this->request('GET', 'domains/info', ['DomainName' => $domainName]);

            return [
                'result' => self::RESULT_OK,
                'data'   => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'DOMAIN_DETAILS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Modify nameservers for a domain
     * @param string $domainName
     * @param array $nameServers
     * @return array
     */
    public function modifyNameServer($domainName, $nameServers)
    {
        try {
            $payload  = ['domainName' => $domainName, 'nameServers' => array_values($nameServers)];
            $response = $this->request('PUT', 'domains/dns/name-server', $payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'NameServers' => $response['nameServers'] ?? $nameServers
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'MODIFY_NS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Enable Theft Protection Lock for a domain
     * @param string $domainName
     * @return array
     */
    public function enableTheftProtectionLock($domainName)
    {
        try {
            $data     = ['domainName' => $domainName];
            $response = $this->request('POST', 'domains/lock', $data);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'LockStatus' => true
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'ENABLE_LOCK', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Disable Theft Protection Lock for a domain
     * @param string $domainName
     * @return array
     */
    public function disableTheftProtectionLock($domainName)
    {
        try {
            $data     = ['domainName' => $domainName];
            $response = $this->request('POST', 'domains/unlock', $data);
            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'LockStatus' => false
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'DISABLE_LOCK', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Add child nameserver for a domain
     * @param string $domainName
     * @param string $nameServer (hostname of child nameserver, e.g., ns1child.example.com)
     * @param string $ipAddress (IP of child nameserver)
     * @return array
     */
    public function addChildNameServer($domainName, $nameServer, $ipAddress)
    {
        try {
            $payload = [
                'domainName'  => $domainName,
                'hostName'    => $nameServer,
                'ipAddresses' => [
                    [
                        'ipAddress' => $ipAddress,
                        'ipVersion' => filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'v4' : 'v6'
                    ]
                ]
            ];
            $response = $this->request('POST', 'domains/dns/host', $payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'NameServer' => $nameServer,
                    'IPAdresses' => [$ipAddress]
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'ADD_CHILD_NS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Delete child nameserver from a domain
     * @param string $domainName
     * @param string $nameServer (hostname of child nameserver to delete)
     * @return array
     */
    public function deleteChildNameServer($domainName, $nameServer)
    {
        try {
            $payload = [
                'domainName' => $domainName,
                'hostName'   => $nameServer
            ];
            $response = $this->request('DELETE', 'domains/dns/host' ,$payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'NameServer' => $nameServer
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'DELETE_CHILD_NS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Modify IP address of child nameserver
     * @param string $domainName
     * @param string $nameServer (hostname of child nameserver)
     * @param string $ipAddress (new IP address)
     * @return array
     */
    public function modifyChildNameServer($domainName, $nameServer, $ipAddress)
    {
        try {
            $payload = [
                'domainName'  => $domainName,
                'hostName'    => $nameServer,
                'newHostName' => $nameServer,
                'ipAddresses' => [
                    [
                        'ipAddress' => $ipAddress,
                        'ipVersion' => filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'v4' : 'v6'
                    ]
                ]
            ];
            $response = $this->request('PUT', 'domains/dns/host' . $nameServer, $payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'NameServer' => $response['hostName'] ?? $nameServer,
                    'IPAdresses' => $response['ipAddresses'] ?? [$ipAddress]
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'MODIFY_CHILD_NS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get contact information for a domain
     * @param string $domainName
     * @return array
     */
    public function getContacts($domainName)
    {
        try {
            $response = $this->request('GET', "domains/{$domainName}/contacts");

            $contacts = [];
            if (isset($response['contacts']) && is_array($response['contacts'])) {
                foreach ($response['contacts'] as $contact) {
                    $contacts[ucfirst(strtolower($contact['type']))] = $this->parseContactInfo($contact);
                }
            }

            return [
                'result' => self::RESULT_OK,
                'data'   => ['contacts' => $contacts]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'GET_CONTACTS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Saves or updates contact information for all contact types of a domain
     * @param string $domainName
     * @param array $contacts (['Registrant' => [...], 'Admin' => [...], ...])
     * @return array
     */
    public function saveContacts($domainName, $contacts)
    {
        try {
            $payloadContacts = [];
            foreach ($contacts as $type => $details) {
                $payloadContacts[] = $this->parseContact($details, ucfirst(strtolower($type)));
            }
            $response = $this->request('PUT', 'domains/' . $domainName . '/contacts', ['contacts' => $payloadContacts]);

            $parsedContacts = [];
            if (isset($response['contacts']) && is_array($response['contacts'])) {
                foreach ($response['contacts'] as $contact) {
                    $parsedContacts[ucfirst(strtolower($contact['type']))] = $this->parseContactInfo($contact);
                }
            }

            return [
                'result' => self::RESULT_OK,
                'data'   => ['contacts' => $parsedContacts]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'SAVE_CONTACTS', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Start domain transfer to your account
     * @param string $domainName
     * @param string $eppCode
     * @param int $period
     * @param array $contacts
     * @return array
     */
    public function transfer($domainName, $eppCode, $period, $contacts = [])
    {
        try {
            $payloadContacts = [];
            if (!empty($contacts)) {
                foreach ($contacts as $type => $details) {
                    $payloadContacts[] = $this->parseContact($details, ucfirst(strtolower($type)));
                }
            }

            $payload = [
                'domainName' => $domainName,
                'authCode'   => $eppCode,
                'period'     => $period,
                'contacts'   => $payloadContacts
            ];

            $response = $this->request('POST', 'domains/transfer', $payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'TRANSFER_DOMAIN', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Cancel pending incoming transfer
     * @param string $domainName
     * @return array
     */
    public function cancelTransfer($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/transfer/cancel");

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'DomainName' => $domainName,
                    'Status'     => $response['status'] ?? 'Cancelled'
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'CANCEL_TRANSFER', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Approve pending outgoing transfer
     * @param string $domainName
     * @return array
     */
    public function approveTransfer($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/transfer/approve");

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'DomainName' => $domainName,
                    'Status'     => $response['status'] ?? 'Approved'
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'APPROVE_TRANSFER', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Reject pending outgoing transfer
     * @param string $domainName
     * @return array
     */
    public function rejectTransfer($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/transfer/reject");

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'DomainName' => $domainName,
                    'Status'     => $response['status'] ?? 'Rejected'
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'REJECT_TRANSFER', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Renew domain registration
     * @param string $domainName
     * @param int $period
     * @return array
     */
    public function renew($domainName, $period)
    {
        try {
            $payload  = ['period' => $period];
            $response = $this->request('POST', 'domains/' . $domainName . '/renew', $payload);

            if ($response["expirationDate"] ?? false) {
                return [
                    'result' => self::RESULT_OK,
                    'data'   => [
                        'DomainName'     => $domainName,
                        'ExpirationDate' => $response['expirationDate'] ?? '',
                        'Status'         => $response['status'] ?? 'Renewed'
                    ]
                ];
            } else {
                return [
                    'result' => self::RESULT_ERROR,
                    'error'  => $this->setError("DOMAIN_RENEW")
                ];
                $this->sendErrorToSentryAsync(new Exception("[DOMAIN_RENEW] " . self::$DEFAULT_ERRORS['DOMAIN_RENEW']['description']));
            }
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'RENEW_DOMAIN', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Register new domain with contact information
     * @param string $domainName
     * @param int $period
     * @param array $contacts
     * @param array $nameServers
     * @param bool $eppLock
     * @param bool $privacyLock
     * @param array $additionalAttributes
     * @return array
     */
    public function registerWithContactInfo(
        $domainName,
        $period,
        $contacts,
        $nameServers = [],
        $eppLock = true,
        $privacyLock = false,
        $additionalAttributes = []
    ) {
        try {
            $payloadContacts = [];
            foreach ($contacts as $type => $details) {
                $payloadContacts[] = $this->parseContact($details, ucfirst(strtolower($type)));
            }

            $payload = [
                'domainName'           => $domainName,
                'period'               => $period,
                'nameServers'          => empty($nameServers) ? self::$DEFAULT_NAMESERVERS : $nameServers,
                'isLocked'             => $eppLock,
                'privacyEnabled'       => $privacyLock,
                'contacts'             => $payloadContacts,
                'additionalAttributes' => $additionalAttributes
            ];

            $response = $this->request('POST', 'domains', $payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'REGISTER_DOMAIN', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Modify privacy protection status
     * @param string $domainName
     * @param bool $status
     * @param string $reason
     * @return array
     */
    public function modifyPrivacyProtectionStatus($domainName, $status, $reason = 'Owner request')
    {
        try {
            // Eğer reason boş ise, varsayılan değeri kullan
            if (empty($reason)) {
                $reason = self::$DEFAULT_REASON;
            }

            $payload  = ['domainName' => $domainName, 'privacyStatus' => $status===true];
            $response = $this->request('PUT', "domains/privacy", $payload);

            return [
                'result' => self::RESULT_OK,
                'data'   => [
                    'DomainName'              => $domainName,
                    'PrivacyProtectionStatus' => $status
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'MODIFY_PRIVACY', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Synchronize domain information with registry
     * @param string $domainName
     * @return array
     */
    public function syncFromRegistry($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/sync");

            return [
                'result' => self::RESULT_OK,
                'data'   => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => self::RESULT_ERROR,
                'error'  => $this->setError($e->getCode() ?: 'SYNC_DOMAIN', $e->getMessage(),
                    $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Parse domain information from response
     * @param array $data
     * @return array
     */
    private function parseDomainInfo($data)
    {
        if (empty($data)) {
            return [];
        }
        return [
            'data'   => [
                'ID'                      => (int)($data['id'] ?? 0),
                'Status'                  => (string)($data['status'] ?? ''),
                'DomainName'              => (string)($data['domainName'] ?? ($data['name'] ?? '')),
                'AuthCode'                => (string)($data['authCode'] ?? ($data['eppCode'] ?? '')),
                'LockStatus'              => $data['lockStatus'] ? 'true' : 'false',
                'PrivacyProtectionStatus' => $data['privacyProtectionStatus'] ? 'true' : 'false',
                'IsChildNameServer'       => !empty($data['hosts']) ? 'true' : 'false',
                'Contacts'                => [
                    'Billing'        => [
                        'ID' => $data['contacts'][array_search('Billing',
                                array_column($data['contacts'], 'ContactType'))]['handle'] ?? 0
                    ],
                    'Technical'      => [
                        'ID' => $data['contacts'][array_search('Tech',
                                array_column($data['contacts'], 'ContactType'))]['handle'] ?? 0
                    ],
                    'Administrative' => [
                        'ID' => $data['contacts'][array_search('Admin',
                                array_column($data['contacts'], 'ContactType'))]['handle'] ?? 0
                    ],
                    'Registrant'     => [
                        'ID' => $data['contacts'][array_search('Registrant',
                                array_column($data['contacts'], 'ContactType'))]['handle'] ?? 0
                    ]
                ],
                'Dates'                   => [
                    'Start'         => (string)($data['startDate'] ?? ''),
                    'Expiration'    => (string)($data['expirationDate'] ?? ''),
                    'RemainingDays' => (int)($data['remainingDay'] ?? 0)
                ],
                'NameServers'             => isset($data['nameservers']) ? array_map('strval',
                    $data['nameservers']) : [],
                'Additional'              => isset($data['additionalAttributes']) ? (array)$data['additionalAttributes'] : [],
                'ChildNameServers'        => isset($data['hosts']) ? array_map(function ($ns) {
                    return [
                        'ns' => $ns['name'],
                        'ip' => array_map(function ($ip) {
                            return $ip['ipAddress'];
                        }, $ns['ipAddresses'] ?? [])
                    ];
                }, $data['hosts']) : []
            ],
            'result' => self::RESULT_OK
        ];
    }

    /**
     * Parse contact information from response
     * @param array $data
     * @return array
     */
    private function parseContactInfo($data)
    {
        if (empty($data)) {
            return [];
        }
        return [
            'ID'         => $data['id'] ?? '',
            'Status'     => $data['status'] ?? 'Active',
            'AuthCode'   => $data['authCode'] ?? '',
            'FirstName'  => $data['firstName'] ?? '',
            'LastName'   => $data['lastName'] ?? '',
            'Company'    => $data['organizationName'] ?? ($data['company'] ?? ''),
            'EMail'      => $data['emailAddress'] ?? ($data['email'] ?? ''),
            'Type'       => $data['type'] ?? '',
            'Address'    => [
                'Line1'   => $data['addressLine1'] ?? ($data['street1'] ?? ''),
                'Line2'   => $data['addressLine2'] ?? ($data['street2'] ?? ''),
                'Line3'   => $data['addressLine3'] ?? ($data['street3'] ?? ''),
                'State'   => $data['stateOrProvince'] ?? ($data['state'] ?? ''),
                'City'    => $data['city'] ?? '',
                'Country' => $data['countryCode'] ?? ($data['country'] ?? ''),
                'ZipCode' => $data['postalCode'] ?? ($data['zipCode'] ?? '')
            ],
            'Phone'      => [
                'Phone' => [
                    'Number'      => $data['phoneNumber'] ?? ($data['phone'] ?? ''),
                    'CountryCode' => $data['phoneCountryCode'] ?? ''
                ],
                'Fax'   => [
                    'Number'      => $data['faxNumber'] ?? ($data['fax'] ?? ''),
                    'CountryCode' => $data['faxCountryCode'] ?? ''
                ]
            ],
            'Additional' => $data['additionalAttributes'] ?? []
        ];
    }

    /**
     * Parse contact for request
     * @param array $contact
     * @param string $type
     * @return array
     */
    private function parseContact($contact, $type)
    {
        return [
            'type'             => $type,
            'firstName'        => $contact['FirstName'] ?? '',
            'lastName'         => $contact['LastName'] ?? '',
            'organizationName' => $contact['Company'] ?? '',
            'emailAddress'     => $contact['EMail'] ?? '',
            'addressLine1'     => $contact['Address']['Line1'] ?? '',
            'addressLine2'     => $contact['Address']['Line2'] ?? '',
            'addressLine3'     => $contact['Address']['Line3'] ?? '',
            'city'             => $contact['Address']['City'] ?? '',
            'stateOrProvince'  => $contact['Address']['State'] ?? '',
            'countryCode'      => $contact['Address']['Country'] ?? '',
            'postalCode'       => $contact['Address']['ZipCode'] ?? '',
            'phoneNumber'      => ($contact['Phone']['Phone']['CountryCode'] ?? '') . ($contact['Phone']['Phone']['Number'] ?? ''),
            'faxNumber'        => ($contact['Phone']['Fax']['CountryCode'] ?? '') . ($contact['Phone']['Fax']['Number'] ?? '')
        ];
    }

    /**
     * Domain is TR type
     * @param string $domain
     * @return bool
     */
    public function isTrTLD($domain)
    {
        return strtolower(substr($domain, -3)) === '.tr';
    }

    /**
     * Check if domain transfer is possible
     * Not supported in REST API mode.
     *
     * @param string $domainName Domain name to check transfer for
     * @param string $authcode Authorization/EPP code for transfer
     * @return array
     * @throws Exception
     */
    public function checkTransfer($domainName, $authcode)
    {
        throw new Exception("checkTransfer is not supported in REST API");
    }

    /**
     * Validate and normalize contact information
     *
     * @param array $contact Contact data to validate
     * @return array Validated contact information
     */
    public function validateContact($contact)
    {
        // Varsayılan değerleri tanımla
        $defaults = [
            "FirstName"        => "Isimyok",
            "LastName"         => "Isimyok",
            "AddressLine1"     => "Addres yok",
            "City"             => "ISTANBUL",
            "Country"          => "TR",
            "ZipCode"          => "34000",
            "Phone"            => "5555555555",
            "PhoneCountryCode" => "90"
        ];

        // Eksik anahtarları varsayılan değerlerle doldur
        foreach ($defaults as $key => $value) {
            if (!isset($contact[$key])) {
                $contact[$key] = $value;
            }
        }

        // Boş değerleri kontrol et ve varsayılan değerlerle doldur
        if (strlen(trim($contact["FirstName"])) == 0) {
            $contact["FirstName"] = $defaults["FirstName"];
        }
        if (strlen(trim($contact["LastName"])) == 0) {
            $contact["LastName"] = $contact["FirstName"];
        }
        if (strlen(trim($contact["AddressLine1"])) == 0) {
            $contact["AddressLine1"] = $defaults["AddressLine1"];
        }
        if (strlen(trim($contact["City"])) == 0) {
            $contact["City"] = $defaults["City"];
        }
        if (strlen(trim($contact["Country"])) == 0) {
            $contact["Country"] = $defaults["Country"];
        }
        if (strlen(trim($contact["ZipCode"])) == 0) {
            $contact["ZipCode"] = $defaults["ZipCode"];
        }

        // Telefon numarası işleme
        $tmpPhone = isset($contact["Phone"]) ? preg_replace('/[^0-9]/', '', $contact["Phone"]) : '';
        if (strlen($tmpPhone) == 10) {
            $contact["PhoneCountryCode"] = '';
            $contact["Phone"]            = $tmpPhone;
        } elseif (strlen($tmpPhone) == 11 && substr($tmpPhone, 0, 1) == '9') {
            $contact["PhoneCountryCode"] = substr($tmpPhone, 0, 2);
            $contact["Phone"]            = substr($tmpPhone, 2);
        } elseif (strlen($tmpPhone) == 12 && substr($tmpPhone, 0, 2) == '90') {
            $contact["PhoneCountryCode"] = substr($tmpPhone, 0, 2);
            $contact["Phone"]            = substr($tmpPhone, 2);
        } else {
            $contact["PhoneCountryCode"] = $defaults["PhoneCountryCode"];
            $contact["Phone"]            = $tmpPhone ?: $defaults["Phone"];
        }

        if (strlen(trim($contact["PhoneCountryCode"])) == 0) {
            $contact["PhoneCountryCode"] = $defaults["PhoneCountryCode"];
        }
        if (strlen(trim($contact["Phone"])) == 0) {
            $contact["Phone"] = $defaults["Phone"];
        }

        return $contact;
    }
} 