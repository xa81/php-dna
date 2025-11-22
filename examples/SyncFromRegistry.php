<?php
/**
 * Created by PhpStorm.
 * User: bunyaminakcay
 * Project name php-dna
 * 4.03.2023 15:23
 * Bünyamin AKÇAY <bunyamin@bunyam.in>
 */
require_once __DIR__.'/../DomainNameApi/DomainNameAPI_PHPLibrary.php';

$username = 'your-username@example.com';
$password = 'your-password';

$dna = new \DomainNameApi\DomainNameAPI_PHPLibrary($username,$password);

/**
 * Sync from registry, domain information will be updated from registry
 * @param string $DomainName
 * @return array
 */
$renew=$dna->syncFromRegistry('example.com');
print_r($renew);
