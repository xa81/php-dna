<?php
/**
 * Created by PhpStorm.
 * User: bunyaminakcay
 * Project name whmcs-dna
 * 20.11.2022 00:13
 * Bünyamin AKÇAY <bunyamin@bunyam.in>
 */

namespace DomainNameApi;

require_once __DIR__ . '/DNARest.php';
require_once __DIR__ . '/DomainNameAPI_PHPLibrary.php';

use Exception;

class Api
{
    private $api;
    private $lastError;
    // Trait'teki sabitlere erişim için statik değişkenler
    public static $DEFAULT_NAMESERVERS;
    public static $DEFAULT_REASON;

    // Statik değişkenlerin başlatılması için initialize metodu
    public static function initialize()
    {
        // SharedApiConfigAndUtilsTrait'den değerleri alıyoruz
        self::$DEFAULT_NAMESERVERS = SharedApiConfigAndUtilsTrait::$DEFAULT_NAMESERVERS;
        self::$DEFAULT_REASON      = SharedApiConfigAndUtilsTrait::$DEFAULT_REASON;
    }

    /**
     * Api constructor.
     * Geriye dönük uyumlu seçim mantığı:
     * - SOAP: $username ve $password kullanıcı adı/şifre gibi görünüyorsa
     * - REST (legacy): $useRest=true ise kullanıcı adı/şifre+resellerId ile
     * - REST (token): $username UUID formatında ve $resellerId=null ise ($username,$password) = (resellerId,token)
     *
     * @param string $username
     * @param string $password
     * @param bool $useRest
     * @return array
     */
    public function __construct(string $username, string $password)
    {
        if (empty($username) || empty($password)) {
            return [
                'result' => 'ERROR',
                'error'  => [
                    'code'        => 'CREDENTIALS',
                    'message'     => 'Username and password are required',
                    'description' => 'The provided API credentials are invalid'
                ]
            ];
        }

        // Otomatik tespit: username UUID formatındaysa, token modu ile REST kullan
        if ($this->looksLikeUuid($username)) {
            // ($username,$password) = (resellerIdUUID, bearerToken)
            $this->api = new DNARest($username, $password);
        } else {
            // Varsayılan: SOAP
            $this->api = new DomainNameAPI_PHPLibrary($username, $password);
        }
    }

    /**
     * Basit UUID kontrolü (v4 benzeri biçim). Büyük/küçük harf duyarsız.
     * @param string $value
     * @return bool
     */
    private function looksLikeUuid(string $value): bool
    {
        return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
    }

    /**
     * Get last error message
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get Current account details with balance
     * @return array
     */
    public function GetResellerDetails()
    {
        try {
           $response = $this->api->GetResellerDetails();
           return $response;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'result' => 'ERROR',
                'error'  => [
                    'code'        => 'RESELLER_DETAILS',
                    'message'     => 'Failed to get reseller details',
                    'description' => $e->getMessage()
                ]
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
            if (!in_array($currencyId, ['USD', 'TRY'])) {
                return [
                    'result' => 'ERROR',
                    'error'  => [
                        'code'        => 'INVALID_CURRENCY',
                        'message'     => 'Invalid currency ID',
                        'description' => 'Currency must be USD or TRY'
                    ]
                ];
            }
            return $this->api->GetCurrentBalance($currencyId);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'result' => 'ERROR',
                'error'  => [
                    'code'        => 'BALANCE',
                    'message'     => 'Failed to get current balance',
                    'description' => $e->getMessage()
                ]
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
    public function CheckAvailability($domains, $extensions, $period=1, $Command='create')
    {
        try {

            if (empty($domains) || empty($extensions)) {
                return [
                    'result' => 'ERROR',
                    'error'  => [
                        'code'        => 'INVALID_PARAMETERS',
                        'message'     => 'Invalid parameters',
                        'description' => 'Domains and extensions are required'
                    ]
                ];
            }
            if ($period < 1 || $period>9) {
                return [
                    'result' => 'ERROR',
                    'error'  => [
                        'code'        => 'INVALID_PERIOD',
                        'message'     => 'Invalid period',
                        'description' => 'Period must be greater than 0'
                    ]
                ];
            }
            return $this->api->CheckAvailability($domains, $extensions, $period, $Command);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'result' => 'ERROR',
                'error'  => [
                    'code'        => 'AVAILABILITY',
                    'message'     => 'Failed to check availability',
                    'description' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Get list of domains in your account
     * @param array $extra_parameters
     * @return array
     * @throws Exception
     */
    public function GetList($extra_parameters = [])
    {
        try {
            return $this->api->GetList($extra_parameters);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to get domain list: ' . $e->getMessage());
        }
    }

    /**
     * Get TLD list and pricing matrix
     * @param int $count
     * @return array
     * @throws Exception
     */
    public function GetTldList($count = 20)
    {
        try {
            return $this->api->GetTldList($count);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to get TLD list: ' . $e->getMessage());
        }
    }

    /**
     * Get detailed information for a domain
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function GetDetails($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->GetDetails($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to get domain details: ' . $e->getMessage());
        }
    }

    /**
     * Modify nameservers for a domain
     * @param string $domainName
     * @param array $nameServers
     * @return array
     * @throws Exception
     */
    public function ModifyNameServer($domainName, $nameServers)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            if (empty($nameServers) || !is_array($nameServers)) {
                throw new Exception('Name servers must be a non-empty array');
            }
            return $this->api->ModifyNameServer($domainName, $nameServers);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to modify nameservers: ' . $e->getMessage());
        }
    }

    /**
     * Enable Theft Protection Lock for a domain
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function EnableTheftProtectionLock($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->EnableTheftProtectionLock($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to enable theft protection: ' . $e->getMessage());
        }
    }

    /**
     * Disable Theft Protection Lock for a domain
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function DisableTheftProtectionLock($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->DisableTheftProtectionLock($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to disable theft protection: ' . $e->getMessage());
        }
    }

    /**
     * Add child nameserver for a domain
     * @param string $domainName
     * @param string $nameServer
     * @param string $ipAddress
     * @return array
     * @throws Exception
     */
    public function AddChildNameServer($domainName, $nameServer, $ipAddress)
    {
        try {
            if (empty($domainName) || empty($nameServer) || empty($ipAddress)) {
                throw new Exception('Domain name, nameserver and IP address are required');
            }
            if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                throw new Exception('Invalid IP address format');
            }
            return $this->api->AddChildNameServer($domainName, $nameServer, $ipAddress);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to add child nameserver: ' . $e->getMessage());
        }
    }

    /**
     * Delete child nameserver from a domain
     * @param string $domainName
     * @param string $nameServer
     * @return array
     * @throws Exception
     */
    public function DeleteChildNameServer($domainName, $nameServer)
    {
        try {
            if (empty($domainName) || empty($nameServer)) {
                throw new Exception('Domain name and nameserver are required');
            }
            return $this->api->DeleteChildNameServer($domainName, $nameServer);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to delete child nameserver: ' . $e->getMessage());
        }
    }

    /**
     * Modify IP address of child nameserver
     * @param string $domainName
     * @param string $nameServer
     * @param string $ipAddress
     * @return array
     * @throws Exception
     */
    public function ModifyChildNameServer($domainName, $nameServer, $ipAddress)
    {
        try {
            if (empty($domainName) || empty($nameServer) || empty($ipAddress)) {
                throw new Exception('Domain name, nameserver and IP address are required');
            }
            if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                throw new Exception('Invalid IP address format');
            }
            return $this->api->ModifyChildNameServer($domainName, $nameServer, $ipAddress);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to modify child nameserver: ' . $e->getMessage());
        }
    }

    /**
     * Get contact information for a domain
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function GetContacts($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->GetContacts($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to get contacts: ' . $e->getMessage());
        }
    }

    /**
     * Saves or updates contact information for all contact types of a domain
     * @param string $domainName
     * @param array $contacts
     * @return array
     * @throws Exception
     */
    public function SaveContacts($domainName, $contacts)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            if (empty($contacts) || !is_array($contacts)) {
                throw new Exception('Contacts must be a non-empty array');
            }
            return $this->api->SaveContacts($domainName, $contacts);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to save contacts: ' . $e->getMessage());
        }
    }

    /**
     * Start domain transfer to your account
     * @param string $domainName
     * @param string $eppCode
     * @param int $period
     * @param array $contacts
     * @return array
     * @throws Exception
     */
    public function Transfer($domainName, $eppCode, $period, $contacts = [])
    {
        try {
            if (empty($domainName) || empty($eppCode)) {
                throw new Exception('Domain name and EPP code are required');
            }
            if ($period < 1) {
                throw new Exception('Period must be greater than 0');
            }
            return $this->api->Transfer($domainName, $eppCode, $period, $contacts);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to transfer domain: ' . $e->getMessage());
        }
    }

    /**
     * Cancel pending incoming transfer
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function CancelTransfer($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->CancelTransfer($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to cancel transfer: ' . $e->getMessage());
        }
    }

    /**
     * Approve pending outgoing transfer
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function ApproveTransfer($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->ApproveTransfer($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to approve transfer: ' . $e->getMessage());
        }
    }

    /**
     * Reject pending outgoing transfer
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function RejectTransfer($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->RejectTransfer($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to reject transfer: ' . $e->getMessage());
        }
    }

    /**
     * Renew domain registration
     * @param string $domainName
     * @param int $period
     * @return array
     * @throws Exception
     */
    public function Renew($domainName, $period)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            if ($period < 1) {
                throw new Exception('Period must be greater than 0');
            }
            return $this->api->Renew($domainName, $period);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to renew domain: ' . $e->getMessage());
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
     * @throws Exception
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
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            if ($period < 1) {
                throw new Exception('Period must be greater than 0');
            }
            if (empty($contacts) || !is_array($contacts)) {
                throw new Exception('Contacts must be a non-empty array');
            }

            // Nameservers boşsa varsayılan değerleri kullan
            if (empty($nameServers)) {
                $nameServers = self::$DEFAULT_NAMESERVERS;
            }

            return $this->api->RegisterWithContactInfo($domainName, $period, $contacts, $nameServers, $eppLock,
                $privacyLock, $additionalAttributes);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to register domain: ' . $e->getMessage());
        }
    }

    /**
     * Modify privacy protection status
     * @param string $domainName
     * @param bool $status
     * @param string $reason
     * @return array
     * @throws Exception
     */
    public function ModifyPrivacyProtectionStatus($domainName, $status, $reason = 'Owner request')
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }

            // Reason boşsa varsayılan değeri kullan
            if (empty($reason)) {
                $reason = self::$DEFAULT_REASON;
            }

            return $this->api->ModifyPrivacyProtectionStatus($domainName, $status, $reason);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to modify privacy protection: ' . $e->getMessage());
        }
    }

    /**
     * Synchronize domain information with registry
     * @param string $domainName
     * @return array
     * @throws Exception
     */
    public function SyncFromRegistry($domainName)
    {
        try {
            if (empty($domainName)) {
                throw new Exception('Domain name is required');
            }
            return $this->api->SyncFromRegistry($domainName);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            throw new Exception('Failed to sync from registry: ' . $e->getMessage());
        }
    }

    /**
     * Domain is TR type
     * @param string $domain
     * @return array
     */
    public function isTrTLD($domain)
    {
        try {
            if (!$this->api) {
                return [
                    'result' => 'ERROR',
                    'error'  => [
                        'code'        => 'NOT_INITIALIZED',
                        'message'     => 'API not initialized',
                        'description' => 'API must be initialized before use'
                    ]
                ];
            }
            if (empty($domain)) {
                return [
                    'result' => 'ERROR',
                    'error'  => [
                        'code'        => 'INVALID_DOMAIN',
                        'message'     => 'Invalid domain',
                        'description' => 'Domain name is required'
                    ]
                ];
            }
            $result = $this->api->isTrTLD($domain);
            return [
                'result' => 'OK',
                'data'   => [
                    'isTrTLD' => $result
                ]
            ];
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'result' => 'ERROR',
                'error'  => [
                    'code'        => 'TLD_CHECK',
                    'message'     => 'Failed to check TLD',
                    'description' => $e->getMessage()
                ]
            ];
        }
    }
} 