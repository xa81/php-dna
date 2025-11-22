<?php
/**
 * Created by PhpStorm.
 * User: bunyaminakcay
 * Project name php-dna
 * 4.03.2023 15:19
 * Bünyamin AKÇAY <bunyamin@bunyam.in>
 */


require_once __DIR__.'/../DomainNameApi/DomainNameAPI_PHPLibrary.php';

$username = 'your-username@example.com';
$password = 'your-password';

$dna = new \DomainNameApi\DomainNameAPI_PHPLibrary($username,$password);

/**
 * Renew domain
 * @param string $DomainName
 * @param int $Period
 * @return array
 */
$renew=$dna->renew('example.com',2);
print_r($renew);

/**
 * Array
(
    [result] => OK
    [data] => => Array
        (
            [ExpirationDate] =>2025-03-04 00:00:00
   )

)
 */
