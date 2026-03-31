#!/usr/bin/env php
<?php
/**
 * Deep Compare Script for A=B=C Response Verification
 *
 * Usage: php compare_responses.php <file_a> <file_b> [file_c]
 *
 * Compares response STRUCTURES (keys, types, array shapes) and VALUES.
 * Reports every difference found.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php compare_responses.php <file_a> <file_b> [file_c]\n");
    exit(1);
}

$fileA = $argv[1];
$fileB = $argv[2];
$fileC = $argv[3] ?? null;

$dataA = json_decode(file_get_contents($fileA), true);
$dataB = json_decode(file_get_contents($fileB), true);
$dataC = $fileC ? json_decode(file_get_contents($fileC), true) : null;

$labelA = $dataA['label'] ?? 'A';
$labelB = $dataB['label'] ?? 'B';
$labelC = $dataC ? ($dataC['label'] ?? 'C') : null;

// Dynamic fields that are expected to differ in VALUE but not in TYPE/STRUCTURE
$dynamicValuePaths = [
    // Balance amounts change
    '~\.balance$~i',
    '~\.Balance$~i',
    '~\.balances\.\d+\.balance$~i',
    // Timing
    '~\.time_ms$~i',
    // Remaining days can change daily
    '~\.RemainingDays$~i',
    // Auth codes can differ between accounts
    '~\.AuthCode$~i',
    '~\.Auth$~i',
    // IDs differ between accounts
    '~\.ID$~',
    '~\.id$~',
    // Status messages from server
    '~\.OperationMessage$~i',
    // Error details from different backends
    '~\.error\.Details$~i',
    '~\.error\.Message$~i',
    // Price can change
    '~\.Price$~i',
    // Dates
    '~\.Dates\.Start$~i',
    '~\.Dates\.Expiration$~i',
    // Domain names differ between test accounts
    '~\.DomainName$~i',
    '~\.DomainName$~',
    // Contact specific values
    '~\.FirstName$~i', '~\.LastName$~i', '~\.Company$~i', '~\.EMail$~i',
    '~\.Address\.Line1$~i', '~\.Address\.Line2$~i', '~\.Address\.Line3$~i',
    '~\.Address\.City$~i', '~\.Address\.Country$~i', '~\.Address\.ZipCode$~i',
    '~\.Address\.State$~i',
    '~\.Phone\.Phone\.Number$~i', '~\.Phone\.Phone\.CountryCode$~i',
    '~\.Phone\.Fax\.Number$~i', '~\.Phone\.Fax\.CountryCode$~i',
    '~\.Status$~i',
    // NameServer values
    '~\.NameServers\.\d+$~',
    // TLD specific
    '~\.TLD\.\d+~',
    // Additional attributes vary
    '~\.Additional\.~',
    // ChildNameServers
    '~\.ChildNameServers\.\d+\.ns$~',
    '~\.ChildNameServers\.\d+\.ip$~',
];

function isDynamicPath(string $path): bool {
    global $dynamicValuePaths;
    foreach ($dynamicValuePaths as $pattern) {
        if (preg_match($pattern, $path)) {
            return true;
        }
    }
    return false;
}

$totalDiffs = 0;
$structuralDiffs = 0;
$valueDiffs = 0;
$allDiffs = [];

function deepCompare($a, $b, string $path, string $labelA, string $labelB): array {
    $diffs = [];

    $typeA = gettype($a);
    $typeB = gettype($b);

    // Type mismatch is ALWAYS a structural diff
    if ($typeA !== $typeB) {
        // Special case: int vs float (e.g., 0 vs 0.0) - acceptable
        if (($typeA === 'integer' && $typeB === 'double') || ($typeA === 'double' && $typeB === 'integer')) {
            if ((float)$a !== (float)$b && !isDynamicPath($path)) {
                $diffs[] = [
                    'path'     => $path,
                    'type'     => 'value',
                    'severity' => 'VALUE_DIFF',
                    'detail'   => "$labelA=" . json_encode($a) . " ($typeA) vs $labelB=" . json_encode($b) . " ($typeB)",
                ];
            }
            return $diffs;
        }

        // string "true" vs bool true - check for this pattern
        if (($typeA === 'string' && $typeB === 'boolean') || ($typeA === 'boolean' && $typeB === 'string')) {
            $diffs[] = [
                'path'     => $path,
                'type'     => 'structural',
                'severity' => 'TYPE_MISMATCH',
                'detail'   => "$labelA type=$typeA (" . json_encode($a) . ") vs $labelB type=$typeB (" . json_encode($b) . ")",
            ];
            return $diffs;
        }

        $diffs[] = [
            'path'     => $path,
            'type'     => 'structural',
            'severity' => 'TYPE_MISMATCH',
            'detail'   => "$labelA type=$typeA vs $labelB type=$typeB",
        ];
        return $diffs;
    }

    if (is_array($a)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);

        // Check for missing keys
        $missingInB = array_diff($keysA, $keysB);
        $missingInA = array_diff($keysB, $keysA);

        foreach ($missingInB as $key) {
            $diffs[] = [
                'path'     => "$path.$key",
                'type'     => 'structural',
                'severity' => 'MISSING_KEY',
                'detail'   => "Key exists in $labelA but missing in $labelB",
            ];
        }
        foreach ($missingInA as $key) {
            $diffs[] = [
                'path'     => "$path.$key",
                'type'     => 'structural',
                'severity' => 'EXTRA_KEY',
                'detail'   => "Key exists in $labelB but missing in $labelA",
            ];
        }

        // Check key ORDER (important for downstream consumers using array_keys)
        if ($keysA !== $keysB) {
            $commonA = array_values(array_intersect($keysA, $keysB));
            $commonB = array_values(array_intersect($keysB, $keysA));
            if ($commonA !== $commonB) {
                $diffs[] = [
                    'path'     => $path,
                    'type'     => 'structural',
                    'severity' => 'KEY_ORDER',
                    'detail'   => "$labelA keys=[" . implode(',', $keysA) . "] vs $labelB keys=[" . implode(',', $keysB) . "]",
                ];
            }
        }

        // Recurse into common keys
        $commonKeys = array_intersect($keysA, $keysB);
        foreach ($commonKeys as $key) {
            $childDiffs = deepCompare($a[$key], $b[$key], "$path.$key", $labelA, $labelB);
            $diffs = array_merge($diffs, $childDiffs);
        }

        return $diffs;
    }

    // Scalar comparison
    if ($a !== $b && !isDynamicPath($path)) {
        $diffs[] = [
            'path'     => $path,
            'type'     => 'value',
            'severity' => 'VALUE_DIFF',
            'detail'   => "$labelA=" . json_encode($a) . " vs $labelB=" . json_encode($b),
        ];
    }

    return $diffs;
}

// Compare method by method
$methods = array_unique(array_merge(
    array_keys($dataA['results'] ?? []),
    array_keys($dataB['results'] ?? [])
));
sort($methods);

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║           RESPONSE COMPARISON REPORT                           ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  $labelA vs $labelB" . ($labelC ? " vs $labelC" : "") . str_repeat(' ', max(1, 50 - strlen($labelA) - strlen($labelB) - ($labelC ? strlen($labelC) + 4 : 0))) . "║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$pairs = [["$labelA vs $labelB", $dataA, $dataB, $labelA, $labelB]];
if ($dataC) {
    $pairs[] = ["$labelA vs $labelC", $dataA, $dataC, $labelA, $labelC];
}

foreach ($pairs as [$pairName, $left, $right, $lLabel, $rLabel]) {
    echo "━━━ $pairName ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $pairStructural = 0;
    $pairValue = 0;

    foreach ($methods as $method) {
        $respA = $left['results'][$method]['response'] ?? null;
        $respB = $right['results'][$method]['response'] ?? null;

        if ($respA === null && $respB === null) continue;

        if ($respA === null) {
            echo "  ❌ $method: Missing in $lLabel\n";
            $pairStructural++;
            continue;
        }
        if ($respB === null) {
            echo "  ❌ $method: Missing in $rLabel\n";
            $pairStructural++;
            continue;
        }

        $diffs = deepCompare($respA, $respB, $method, $lLabel, $rLabel);

        $methodStructural = count(array_filter($diffs, fn($d) => $d['type'] === 'structural'));
        $methodValue = count(array_filter($diffs, fn($d) => $d['type'] === 'value'));

        if (empty($diffs)) {
            echo "  ✅ $method: IDENTICAL\n";
        } else {
            $icon = $methodStructural > 0 ? '❌' : '⚠️';
            echo "  $icon $method: {$methodStructural} structural, {$methodValue} value diffs\n";

            foreach ($diffs as $diff) {
                $marker = $diff['type'] === 'structural' ? '🔴 STRUCTURAL' : '🟡 VALUE';
                echo "     $marker [{$diff['severity']}] {$diff['path']}\n";
                echo "       {$diff['detail']}\n";
            }
        }

        $pairStructural += $methodStructural;
        $pairValue += $methodValue;
    }

    echo "\n  📊 Summary: $pairStructural structural diffs, $pairValue value diffs\n";
    $structuralDiffs += $pairStructural;
    $valueDiffs += $pairValue;
    echo "\n";
}

echo "══════════════════════════════════════════════════════════════════\n";
echo "FINAL VERDICT:\n";

if ($structuralDiffs === 0 && $valueDiffs === 0) {
    echo "  ✅ ALL RESPONSES IDENTICAL - Safe to deploy!\n";
    exit(0);
} elseif ($structuralDiffs === 0) {
    echo "  ⚠️  No structural differences. $valueDiffs value diffs (may be expected).\n";
    exit(0);
} else {
    echo "  ❌ $structuralDiffs STRUCTURAL DIFFERENCES FOUND! DO NOT DEPLOY!\n";
    echo "  Plus $valueDiffs value diffs.\n";
    exit(1);
}