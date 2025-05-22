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
use CURLFile;

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
    private string $serviceUrl          = "https://rest-test.domainnameapi.com/api";
    private string $application         = "CORE";
    public array   $lastRequest         = [];
    public array   $lastResponse        = [];
    public ?string $lastResponseHeaders = '';
    public array   $lastParsedResponse  = [];
    public string  $lastFunction        = '';
    private        $startAt;

    private $token;
    private $tokenExpire;
    private $authenticated = false;
    private $resellerId;

    /**
     * DNARest constructor.
     * @param string $username
     * @param string $password
     * @param string|null $resellerId
     * @throws Exception
     */
    public function __construct($username, $password, $resellerId = null)
    {
        $this->startAt = microtime(true);
        $this->serviceUsername = $username;
        $this->servicePassword = $password;
        $this->resellerId = $resellerId;
        $this->_setApplication(__FILE__);
        $this->authenticate();
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
     * Authenticate with the API
     * @throws Exception
     */
    private function authenticate()
    {
        $this->lastFunction = __FUNCTION__;
        $this->lastRequest = [
            'url' => 'https://dm.apiname.com/connect/token',
            'payload' => 'client_id, grant_type, etc.'
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://dm.apiname.com/connect/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => 'Dna_PublicApi',
            'grant_type' => 'password',
            'client_secret' => '2b6a1857-2159-4d76-8645-647cc81f2b45',
            'scope' => 'DomainService ProductService OrderService',
            'username' => $this->serviceUsername,
            'password' => $this->servicePassword
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$DEFAULT_TIMEOUT);


        $response_body = curl_exec($ch);
        $this->lastResponseHeaders = 'N/A for token request';
        $this->lastResponse = json_decode($response_body, true) ?? ['raw' => $response_body];

        if (curl_errno($ch)) {
            $error = new Exception('Curl error during authentication: ' . curl_error($ch));
            $this->sendErrorToSentryAsync($error);
        } else {
            curl_close($ch);

            $response_obj = json_decode($response_body);

            if (!$response_obj || isset($response_obj->error)) {
                $errorMessage             = 'Authentication error: ' . ($response_obj->error_description ?? 'Unknown error');
                $error                    = new Exception($errorMessage);
                $this->lastParsedResponse = $this->setError('CREDENTIALS', $errorMessage, $response_body);
                $this->sendErrorToSentryAsync($error);
            } else {
                $this->authenticated      = true;
                $this->token              = $response_obj->access_token;
                $this->tokenExpire        = time() + $response_obj->expires_in;
                $this->lastParsedResponse = ['result' => 'OK', 'data'   => ['token_expires_in' => $response_obj->expires_in]];
            }
        }

    }

    private function ensureAuthenticated(): void
    {
        if (!$this->authenticated || ($this->tokenExpire && time() >= $this->tokenExpire)) {
            $this->authenticate();
        }
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
        $this->ensureAuthenticated();

        if(empty($this->token) || !$this->authenticated) {
            $lastError = $this->lastParsedResponse['Message'] ?? 'Authentication failed , cannot make request';
            throw new Exception($lastError,1);
        }


        $parsedResponse     = [];
        $this->lastFunction = __FUNCTION__ . ':' . $method . ' ' . $endpoint;

        $url = $this->serviceUrl . '/' . ltrim($endpoint, '/');

        $payloadForLog     = $data;
        $this->lastRequest = ['url' => $url, 'method' => $method, 'payload' => $payloadForLog];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$DEFAULT_TIMEOUT);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];
        if ($this->resellerId) {
            $headers[] = '__reseller: ' . $this->resellerId;
        }

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
        $this->lastResponseHeaders = 'Headers not captured in this simplified version';
        $this->lastResponse        = json_decode($response_body, true) ?? ['raw_response' => $response_body];


        var_dump($response_status);

        if (curl_errno($ch)) {
            $error = new Exception('Curl error during request: ' . curl_error($ch));
            $this->sendErrorToSentryAsync($error);
        } else {
            curl_close($ch);


            if($response_status >= 200 && $response_status <= 299){
                 $parsedResponse = json_decode($response_body, true);
                 $this->lastParsedResponse = $parsedResponse;

                if (method_exists($this, 'sendPerformanceMetricsToSentry')) {
                    $duration = (microtime(true) - $this->startAt) * 1000;
                    $this->sendPerformanceMetricsToSentry([
                        'operation'       => $this->lastFunction,
                        'duration'        => floatval($duration),
                        'success'         => true,
                        'timestamp'       => gmdate('Y-m-d\TH:i:s.', time()) . sprintf('%03d',  round(fmod(microtime(true), 1) * 1000)) . 'Z',
                        'start_timestamp' => gmdate('Y-m-d\TH:i:s.', (int)$this->startAt) . sprintf('%03d', round(fmod($this->startAt, 1) * 1000)) . 'Z'
                    ]);
                }
            }else{

                $parsedResponse = json_decode($response_body, true);
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
    public function GetResellerDetails()
    {
        try {
            $response = $this->request('GET', 'deposit/accounts/me');
            return [
                'result' => 'OK',
                'data' => [
                    'id' => $response['id'],
                    'active' => ($response['status'] ?? 'Inactive') === 'Active',
                    'name' => $response['name'],
                    'balance' => $response['balance'],
                    'currency' => $response['currency'],
                    'symbol' => $response['symbol'],
                    'balances' => $response['balances']
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'RESELLER_DETAILS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get Current primary Balance for your account
     * @param string $currencyId
     * @return array
     */
    public function GetCurrentBalance($currencyId = 'USD')
    {
        try {
            $response = $this->request('GET', 'deposit/accounts/me', ['currency' => strtoupper($currencyId)]);
            return [
                'result' => 'OK',
                'data' => [
                    'balance' => $response['balance'],
                    'currency' => $response['currency']
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'BALANCE', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
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
    public function CheckAvailability($domains, $extensions, $period, $Command = 'create')
    {
        try {
            $queries = [];
            foreach ($domains as $domain) {
                foreach ($extensions as $tld) {
                    $queries[] = ['domainName' => $domain . '.' . ltrim($tld, '.')];
                }
            }

            $response = $this->request('POST', 'domains/availability', ['domains' => $queries, 'period' => $period]);
            
            $availabilityData = [];
            if (isset($response) && is_array($response)) {
                foreach($response as $item) {
                    $availabilityData[] = [
                        "TLD"        => $item['tld'] ?? substr(strrchr($item['domainName'], "."), 1),
                        "DomainName" => $item['domainName'],
                        "Status"     => $item['available'] ? 'Available' : 'NotAvailable',
                        "Command"    => $Command,
                        "Period"     => $item['period'] ?? $period,
                        "IsFee"      => $item['isFee'] ?? false,
                        "Price"      => $item['price'] ?? null,
                        "Currency"   => $item['currency'] ?? null,
                        "Reason"     => $item['reason'] ?? ($item['available'] ? '' : 'Domain is not available'),
                    ];
                }
            }

            return [
                'result' => 'OK',
                'data' => $availabilityData
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'AVAILABILITY', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get list of domains in your account
     * @param array $extra_parameters
     * @return array
     */
    public function GetList($extra_parameters = [])
    {
        try {
            $defaults = ['MaxResultCount' => 200, 'SkipCount' => 0];
            $params = array_merge($defaults, $extra_parameters);
            $response = $this->request('GET', 'domains', $params);
            
            return [
                'result' => 'OK',
                'data' => [
                    'Domains' => isset($response['items']) ? array_map([$this, 'parseDomainInfo'], $response['items']) : [],
                    'TotalCount' => $response['totalCount'] ?? 0,
                    'Page' => ($params['SkipCount'] / $params['MaxResultCount']) + 1,
                    'PageSize' => $params['MaxResultCount']
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'DOMAIN_LIST', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get TLD list and pricing matrix
     * @param int $count
     * @return array
     */
    public function GetTldList($count = 20)
    {
        try {
            $response = $this->get('products/tlds', ['pageSize' => $count]);
            
            $tldData = [];
            if(isset($response['items']) && is_array($response['items'])){
                foreach($response['items'] as $tld) {
                    $pricing = [];
                    $currencies = [];
                    if(isset($tld['pricing']) && is_array($tld['pricing'])) {
                        foreach($tld['pricing'] as $priceInfo) {
                            $tradeType = strtolower($priceInfo['tradeType'] ?? 'register');
                            $pricing[$tradeType][$priceInfo['period'] ?? 1] = $priceInfo['price'];
                            $currencies[$tradeType] = $priceInfo['currency'];
                        }
                    }
                    $tldData[] = [
                        'id' => $tld['name'],
                        'status' => $tld['status'] ?? 'Active',
                        'maxchar' => $tld['constraints']['maxLenght'] ?? 255,
                        'maxperiod' => $tld['constraints']['maxPeriod'] ?? 10,
                        'minchar' => $tld['constraints']['minLength'] ?? 1,
                        'minperiod' => $tld['constraints']['minPeriod'] ?? 1,
                        'tld' => $tld['name'],
                        'pricing' => $pricing,
                        'currencies' => $currencies
                    ];
                }
            }

            return [
                'result' => 'OK',
                'data' => [
                    'TLDs' => $tldData,
                    'TotalCount' => $response['totalCount'] ?? count($tldData),
                    'Page' => $response['page'] ?? 1,
                    'PageSize' => $response['pageSize'] ?? $count
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'TLD_LIST', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get detailed information for a domain
     * @param string $domainName
     * @return array
     */
    public function GetDetails($domainName)
    {
        try {
            $response = $this->request('GET', 'domains/'. $domainName);
            
            return [
                'result' => 'OK',
                'data' => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'DOMAIN_DETAILS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Modify nameservers for a domain
     * @param string $domainName
     * @param array $nameServers
     * @return array
     */
    public function ModifyNameServer($domainName, $nameServers)
    {
        try {
            $payload = ['nameServers' => $nameServers];
            $response = $this->request('PUT', 'domains/'. $domainName . '/nameservers', $payload);
            
            return [
                'result' => 'OK',
                'data' => [
                    'NameServers' => $response['nameServers'] ?? $nameServers
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'MODIFY_NS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Enable Theft Protection Lock for a domain
     * @param string $domainName
     * @return array
     */
    public function EnableTheftProtectionLock($domainName)
    {
        try {
            $response = $this->request('POST', 'domains/'. $domainName . '/lock');
            
            return [
                'result' => 'OK',
                'data' => [
                    'LockStatus' => ($response['isLocked'] ?? true)
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'ENABLE_LOCK', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Disable Theft Protection Lock for a domain
     * @param string $domainName
     * @return array
     */
    public function DisableTheftProtectionLock($domainName)
    {
        try {
            $response = $this->request('POST', 'domains/'. $domainName . '/unlock');
            
            return [
                'result' => 'OK',
                'data' => [
                    'LockStatus' => !($response['isLocked'] ?? false)
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'DISABLE_LOCK', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Add child nameserver for a domain
     * @param string $domainName
     * @param string $nameServer (hostname of child nameserver, e.g., ns1.child.example.com)
     * @param string $ipAddress (IP of child nameserver)
     * @return array
     */
    public function AddChildNameServer($domainName, $nameServer, $ipAddress)
    {
        try {
            $payload = [
                'hostName' => $nameServer,
                'ipAddresses' => [$ipAddress]
            ];
            $response = $this->request('POST', 'domains/'. $domainName . '/glue-records', $payload);
            
            return [
                'result' => 'OK',
                'data' => [
                    'NameServer' => $response['hostName'] ?? $nameServer,
                    'IPAdresses' => $response['ipAddresses'] ?? [$ipAddress]
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'ADD_CHILD_NS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Delete child nameserver from a domain
     * @param string $domainName
     * @param string $nameServer (hostname of child nameserver to delete)
     * @return array
     */
    public function DeleteChildNameServer($domainName, $nameServer)
    {
        try {
            $response = $this->request('DELETE', 'domains/'. $domainName . '/glue-records/' . $nameServer);
            
            return [
                'result' => 'OK',
                'data' => [
                    'NameServer' => $nameServer
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'DELETE_CHILD_NS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
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
    public function ModifyChildNameServer($domainName, $nameServer, $ipAddress)
    {
        try {
            $payload = ['ipAddresses' => [$ipAddress]];
            $response = $this->request('PUT', 'domains/'. $domainName . '/glue-records/' . $nameServer, $payload);
            
            return [
                'result' => 'OK',
                'data' => [
                    'NameServer' => $response['hostName'] ?? $nameServer,
                    'IPAdresses' => $response['ipAddresses'] ?? [$ipAddress]
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'MODIFY_CHILD_NS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Get contact information for a domain
     * @param string $domainName
     * @return array
     */
    public function GetContacts($domainName)
    {
        try {
            $response = $this->request('GET', "domains/{$domainName}/contacts");
            
            $contacts = [];
            if (isset($response['contacts']) && is_array($response['contacts'])) {
                 foreach($response['contacts'] as $contact) {
                     $contacts[ucfirst(strtolower($contact['type']))] = $this->parseContactInfo($contact);
                 }
            }

            return [
                'result' => 'OK',
                'data' => ['contacts' => $contacts]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'GET_CONTACTS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Saves or updates contact information for all contact types of a domain
     * @param string $domainName
     * @param array $contacts (['Registrant' => [...], 'Admin' => [...], ...])
     * @return array
     */
    public function SaveContacts($domainName, $contacts)
    {
        try {
            $payloadContacts = [];
            foreach($contacts as $type => $details) {
                $payloadContacts[] = $this->parseContact($details, ucfirst(strtolower($type)));
            }
            $response = $this->request('PUT', 'domains/'. $domainName . '/contacts', ['contacts' => $payloadContacts]);
            
            $parsedContacts = [];
            if (isset($response['contacts']) && is_array($response['contacts'])) {
                 foreach($response['contacts'] as $contact) {
                     $parsedContacts[ucfirst(strtolower($contact['type']))] = $this->parseContactInfo($contact);
                 }
            }

            return [
                'result' => 'OK',
                'data' => ['contacts' => $parsedContacts]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'SAVE_CONTACTS', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
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
    public function Transfer($domainName, $eppCode, $period, $contacts = [])
    {
        try {
            $payloadContacts = [];
            if(!empty($contacts)){
                foreach($contacts as $type => $details) {
                    $payloadContacts[] = $this->parseContact($details, ucfirst(strtolower($type)));
                }
            }

            $payload = [
                'domainName' => $domainName,
                'authCode' => $eppCode,
                'period' => $period,
                'contacts' => $payloadContacts
            ];

            $response = $this->request('POST', 'domains/transfer', $payload);
            
            return [
                'result' => 'OK',
                'data' => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'TRANSFER_DOMAIN', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Cancel pending incoming transfer
     * @param string $domainName
     * @return array
     */
    public function CancelTransfer($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/transfer/cancel");
            
            return [
                'result' => 'OK',
                'data' => [
                    'DomainName' => $domainName,
                    'Status' => $response['status'] ?? 'Cancelled'
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'CANCEL_TRANSFER', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Approve pending outgoing transfer
     * @param string $domainName
     * @return array
     */
    public function ApproveTransfer($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/transfer/approve");
            
            return [
                'result' => 'OK',
                'data' => [
                    'DomainName' => $domainName,
                    'Status' => $response['status'] ?? 'Approved'
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'APPROVE_TRANSFER', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Reject pending outgoing transfer
     * @param string $domainName
     * @return array
     */
    public function RejectTransfer($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/transfer/reject");
            
            return [
                'result' => 'OK',
                'data' => [
                    'DomainName' => $domainName,
                    'Status' => $response['status'] ?? 'Rejected'
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'REJECT_TRANSFER', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Renew domain registration
     * @param string $domainName
     * @param int $period
     * @return array
     */
    public function Renew($domainName, $period)
    {
        try {
            $payload = ['period' => $period];
            $response = $this->request('POST', 'domains/'. $domainName . '/renew', $payload);
            
            if ($response["expirationDate"] ?? false) {
                return [
                    'result' => 'OK',
                    'data' => [
                        'DomainName' => $domainName,
                        'ExpirationDate' => $response['expirationDate'] ?? '',
                        'Status' => $response['status'] ?? 'Renewed'
                    ]
                ];
            } else {
                return [
                    'result' => 'ERROR',
                    'error' => $this->setError("DOMAIN_RENEW")
                ];
                $this->sendErrorToSentryAsync(new Exception("[DOMAIN_RENEW] " . self::$DEFAULT_ERRORS['DOMAIN_RENEW']['description']));
            }
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'RENEW_DOMAIN', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
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
    public function RegisterWithContactInfo(
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
            foreach($contacts as $type => $details) {
                $payloadContacts[] = $this->parseContact($details, ucfirst(strtolower($type)));
            }

            $payload = [
                'domainName' => $domainName,
                'period' => $period,
                'nameServers' => empty($nameServers) ? self::$DEFAULT_NAMESERVERS : $nameServers,
                'isLocked' => $eppLock,
                'privacyEnabled' => $privacyLock,
                'contacts' => $payloadContacts,
                'additionalAttributes' => $additionalAttributes
            ];

            $response = $this->request('POST', 'domains', $payload);
            
            return [
                'result' => 'OK',
                'data' => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'REGISTER_DOMAIN', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
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
    public function ModifyPrivacyProtectionStatus($domainName, $status, $reason = 'Owner request')
    {
        try {
            // Eğer reason boş ise, varsayılan değeri kullan
            if (empty($reason)) {
                $reason = self::$DEFAULT_REASON;
            }
            
            $payload = ['enabled' => $status, 'reason' => $reason];
            $response = $this->request('PUT', "domains/{$domainName}/privacy", $payload);
            
            return [
                'result' => 'OK',
                'data' => [
                    'DomainName' => $domainName,
                    'PrivacyProtectionStatus' => $response['isEnabled'] ?? $status
                ]
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'MODIFY_PRIVACY', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
            ];
        }
    }

    /**
     * Synchronize domain information with registry
     * @param string $domainName
     * @return array
     */
    public function SyncFromRegistry($domainName)
    {
        try {
            $response = $this->request('POST', "domains/{$domainName}/sync");
            
            return [
                'result' => 'OK',
                'data' => $this->parseDomainInfo($response)
            ];
        } catch (Exception $e) {
            return [
                'result' => 'ERROR',
                'error' => $this->setError($e->getCode() ?: 'SYNC_DOMAIN', $e->getMessage(), $this->lastResponse['raw_response'] ?? $e->getTraceAsString())
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
        if (empty($data)) return [];
        return [
            'ID' => $data['id'] ?? ($data['domainName'] ?? ''),
            'Status' => $data['status'] ?? '',
            'DomainName' => $data['domainName'] ?? ($data['name'] ?? ''),
            'AuthCode' => $data['authCode'] ?? ($data['eppCode'] ?? ''),
            'LockStatus' => $data['isLocked'] ?? ($data['locked'] ?? false),
            'PrivacyProtectionStatus' => $data['privacyEnabled'] ?? ($data['privacy'] ?? false),
            'IsChildNameServer' => !empty($data['glueRecords']),
            'Contacts' => [
                'Billing' => ['ID' => $data['contacts']['billing']['id'] ?? ($data['billingContactId'] ?? '')],
                'Technical' => ['ID' => $data['contacts']['technical']['id'] ?? ($data['technicalContactId'] ?? '')],
                'Administrative' => ['ID' => $data['contacts']['administrative']['id'] ?? ($data['adminContactId'] ?? '')],
                'Registrant' => ['ID' => $data['contacts']['registrant']['id'] ?? ($data['registrantContactId'] ?? '')]
            ],
            'Dates' => [
                'Start' => $data['registrationDate'] ?? ($data['createdDate'] ?? ''),
                'Expiration' => $data['expirationDate'] ?? '',
                'RemainingDays' => $data['remainingDays'] ?? ''
            ],
            'NameServers' => $data['nameServers'] ?? [],
            'Additional' => $data['additionalAttributes'] ?? [],
            'ChildNameServers' => array_map(function($ns) {
                return [
                    'ns' => $ns['hostName'],
                    'ip' => $ns['ipAddresses']
                ];
            }, $data['glueRecords'] ?? [])
        ];
    }

    /**
     * Parse contact information from response
     * @param array $data
     * @return array
     */
    private function parseContactInfo($data)
    {
        if (empty($data)) return [];
        return [
            'ID' => $data['id'] ?? '',
            'Status' => $data['status'] ?? 'Active',
            'AuthCode' => $data['authCode'] ?? '',
            'FirstName' => $data['firstName'] ?? '',
            'LastName' => $data['lastName'] ?? '',
            'Company' => $data['organizationName'] ?? ($data['company'] ?? ''),
            'EMail' => $data['emailAddress'] ?? ($data['email'] ?? ''),
            'Type' => $data['type'] ?? '',
            'Address' => [
                'Line1' => $data['addressLine1'] ?? ($data['street1'] ?? ''),
                'Line2' => $data['addressLine2'] ?? ($data['street2'] ?? ''),
                'Line3' => $data['addressLine3'] ?? ($data['street3'] ?? ''),
                'State' => $data['stateOrProvince'] ?? ($data['state'] ?? ''),
                'City' => $data['city'] ?? '',
                'Country' => $data['countryCode'] ?? ($data['country'] ?? ''),
                'ZipCode' => $data['postalCode'] ?? ($data['zipCode'] ?? '')
            ],
            'Phone' => [
                'Phone' => [
                    'Number' => $data['phoneNumber'] ?? ($data['phone'] ?? ''),
                    'CountryCode' => $data['phoneCountryCode'] ?? ''
                ],
                'Fax' => [
                    'Number' => $data['faxNumber'] ?? ($data['fax'] ?? ''),
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
            'type' => $type,
            'firstName' => $contact['FirstName'] ?? '',
            'lastName' => $contact['LastName'] ?? '',
            'organizationName' => $contact['Company'] ?? '',
            'emailAddress' => $contact['EMail'] ?? '',
            'addressLine1' => $contact['Address']['Line1'] ?? '',
            'addressLine2' => $contact['Address']['Line2'] ?? '',
            'addressLine3' => $contact['Address']['Line3'] ?? '',
            'city' => $contact['Address']['City'] ?? '',
            'stateOrProvince' => $contact['Address']['State'] ?? '',
            'countryCode' => $contact['Address']['Country'] ?? '',
            'postalCode' => $contact['Address']['ZipCode'] ?? '',
            'phoneNumber' => ($contact['Phone']['Phone']['CountryCode'] ?? '') . ($contact['Phone']['Phone']['Number'] ?? ''),
            'faxNumber' => ($contact['Phone']['Fax']['CountryCode'] ?? '') . ($contact['Phone']['Fax']['Number'] ?? '')
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
} 