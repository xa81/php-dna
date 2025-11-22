<?php
/**
 * Created by PhpStorm.
 * User: bunyaminakcay
 * Project name php-dna
 * 4.03.2023 15:39
 * Bünyamin AKÇAY <bunyamin@bunyam.in>
 */
require_once __DIR__.'/../DomainNameApi/DomainNameAPI_PHPLibrary.php';

$username = 'your-username@example.com';
$password = 'your-password';

$dna = new \DomainNameApi\DomainNameAPI_PHPLibrary($username,$password);


/**
 * Transfer Domain
 * @param string $DomainName
 * @param string $AuthCode
 * @param int $Period
 * @return array
 */
$result = $dna->checkTransfer('example.com', 'ABC123XYZ456');
print_r($result);

/**
 * Array
(
    [result] => OK
)
 */
