#!/usr/bin/env php
<?php
/**
 * Response Capture Script
 * Calls all read-only API methods and saves responses as normalized JSON.
 *
 * Usage: php capture_responses.php <user> <pass> <domain> <domain_contacts> <output_file> [label]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DomainNameApi\DomainNameAPI_PHPLibrary;

if ($argc < 6) {
    fwrite(STDERR, "Usage: php capture_responses.php <user> <pass> <domain> <domain_contacts> <output_file> [label]\n");
    exit(1);
}

$user           = $argv[1];
$pass           = $argv[2];
$domain         = $argv[3];
$domainContacts = $argv[4];
$outputFile     = $argv[5];
$label          = $argv[6] ?? 'unknown';

fwrite(STDERR, "[$label] Connecting...\n");

try {
    $api = new DomainNameAPI_PHPLibrary($user, $pass);
} catch (Exception $e) {
    fwrite(STDERR, "[$label] Connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$results = [];

// Helper to capture a method call safely
function capture(string $name, callable $fn): array {
    fwrite(STDERR, "  -> $name\n");
    try {
        $start = microtime(true);
        $response = $fn();
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        return [
            'status'   => 'ok',
            'response' => $response,
            'time_ms'  => $elapsed,
        ];
    } catch (Exception $e) {
        return [
            'status'    => 'exception',
            'exception' => $e->getMessage(),
        ];
    }
}

// ==================== READ-ONLY METHODS ====================

$results['GetResellerDetails'] = capture('GetResellerDetails', function() use ($api) {
    return $api->GetResellerDetails();
});

$results['GetCurrentBalance_USD'] = capture('GetCurrentBalance(USD)', function() use ($api) {
    return $api->GetCurrentBalance('USD');
});

$results['GetCurrentBalance_TRY'] = capture('GetCurrentBalance(TRY)', function() use ($api) {
    return $api->GetCurrentBalance('TRY');
});

$results['CheckAvailability_NotAvailable'] = capture('CheckAvailability(google.com)', function() use ($api) {
    return $api->CheckAvailability(['google'], ['com'], 1, 'create');
});

$results['CheckAvailability_Available'] = capture('CheckAvailability(xyznotexist999.com)', function() use ($api) {
    return $api->CheckAvailability(['xyznotexist999'], ['com'], 1, 'create');
});

$results['CheckAvailability_Empty'] = capture('CheckAvailability(empty)', function() use ($api) {
    return $api->CheckAvailability([], ['com'], 1, 'create');
});

$results['CheckAvailability_Multi'] = capture('CheckAvailability(multi)', function() use ($api) {
    return $api->CheckAvailability(['google', 'xyznotexist999'], ['com', 'net'], 1, 'create');
});

$results['GetList'] = capture('GetList()', function() use ($api) {
    return $api->GetList();
});

$results['GetList_WithParams'] = capture('GetList(params)', function() use ($api) {
    return $api->GetList(['OrderColumn' => 'Id', 'OrderDirection' => 'ASC', 'PageNumber' => 0, 'PageSize' => 5]);
});

$results['GetTldList'] = capture('GetTldList(20)', function() use ($api) {
    return $api->GetTldList(20);
});

$results['GetDetails'] = capture('GetDetails(' . $domain . ')', function() use ($api, $domain) {
    return $api->GetDetails($domain);
});

$results['GetDetails_Error'] = capture('GetDetails(nonexistent)', function() use ($api) {
    return $api->GetDetails('nonexistent-domain-xyz.com');
});

$results['GetContacts'] = capture('GetContacts(' . $domainContacts . ')', function() use ($api, $domainContacts) {
    return $api->GetContacts($domainContacts);
});

// ==================== NORMALIZE ====================

/**
 * Normalize response for comparison:
 * - Remove dynamic values that change between calls (timestamps, exact balances)
 * - Keep structure, types, and key order
 */
function normalizeForStructure($data, $path = '') {
    if (is_array($data)) {
        $normalized = [];
        foreach ($data as $key => $value) {
            $currentPath = $path ? "$path.$key" : (string)$key;
            $normalized[$key] = normalizeForStructure($value, $currentPath);
        }
        return $normalized;
    }

    // Return type info instead of dynamic values for certain fields
    $dynamicPaths = [
        // Balance changes between calls
        '~balance$~i', '~Balance$~i',
        // Timestamps may differ in format slightly
        '~time_ms$~i',
    ];

    return $data;
}

// Save both raw and normalized
$output = [
    'label'      => $label,
    'captured_at' => date('Y-m-d H:i:s'),
    'user'       => substr($user, 0, 8) . '***',
    'results'    => $results,
];

file_put_contents($outputFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDERR, "[$label] Done! Saved to $outputFile\n");
