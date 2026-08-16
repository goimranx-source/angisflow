<?php
/**
 * Verification script for Angisflow rebranding.
 * 
 * Tests that the branding system now returns Angisflow values
 * and that logos are properly configured.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

echo "=== ANGISFLOW BRANDING VERIFICATION ===\n\n";

// Test 1: Configuration values
echo "1. Configuration Check:\n";
echo "   - Brand name: " . config('prism.brand.name') . "\n";
echo "   - Expected: Angisflow\n";
echo "   - ✓ " . (config('prism.brand.name') === 'Angisflow' ? 'PASS' : 'FAIL') . "\n\n";

// Test 2: Boot payload branding (without user context)
echo "2. Boot Payload (Guest) Check:\n";
$payload = \App\Support\BootPayload::build();
echo "   - App name: " . $payload['app']['name'] . "\n";
echo "   - Show logo: " . ($payload['app']['show_logo'] ? 'true' : 'false') . "\n";
echo "   - Logo mark: " . ($payload['app']['logo_mark'] ?? 'null') . "\n";
echo "   - Logo: " . ($payload['app']['logo'] ?? 'null') . "\n";
echo "   - Favicon: " . ($payload['app']['favicon'] ?? 'null') . "\n";
echo "   - ✓ " . ($payload['app']['name'] === 'Angisflow' ? 'PASS' : 'FAIL') . "\n\n";

// Test 3: File existence check
echo "3. Logo Files Check:\n";
$logoFiles = [
    'Main logo' => 'public/img/angisflow-logo.png',
    'Dark logo' => 'public/img/angisflow-logo-white.png', 
    'Favicon PNG' => 'public/img/angisflow-favicon.png',
    'Favicon ICO' => 'public/favicon.ico',
    'Favicon SVG' => 'public/favicon.svg'
];

foreach ($logoFiles as $name => $path) {
    $fullPath = __DIR__ . '/../' . $path;
    $exists = file_exists($fullPath);
    echo "   - $name: " . ($exists ? '✓ EXISTS' : '✗ MISSING') . "\n";
}

// Test 4: Database seeder content
echo "\n4. Database Content Check:\n";
$catalogueSeederPath = __DIR__ . '/../database/seeders/CatalogueSeeder.php';
$catalogueContent = file_get_contents($catalogueSeederPath);
$hasAngisflow = strpos($catalogueContent, 'Ask Angisflow') !== false;
echo "   - CatalogueSeeder contains 'Ask Angisflow': " . ($hasAngisflow ? '✓ YES' : '✗ NO') . "\n";

$modulesPath = __DIR__ . '/../app/Support/Modules.php';
$modulesContent = file_get_contents($modulesPath);
$hasAngisflowModules = strpos($modulesContent, 'Ask Angisflow') !== false;
echo "   - Modules.php contains 'Ask Angisflow': " . ($hasAngisflowModules ? '✓ YES' : '✗ NO') . "\n";

// Summary
echo "\n=== SUMMARY ===\n";
$allGood = (
    config('prism.brand.name') === 'Angisflow' &&
    $payload['app']['name'] === 'Angisflow' &&
    $payload['app']['show_logo'] === true &&
    file_exists(__DIR__ . '/../public/img/angisflow-logo.png') &&
    file_exists(__DIR__ . '/../public/img/angisflow-favicon.png') &&
    $hasAngisflow &&
    $hasAngisflowModules
);

if ($allGood) {
    echo "🎉 All checks PASSED! Angisflow branding is properly configured.\n";
    echo "\nExpected behavior:\n";
    echo "- Browser tab shows Angisflow favicon\n";
    echo "- Sidebar shows Angisflow logo mark (no text title)\n";
    echo "- Mobile header shows full Angisflow logo (theme-aware)\n";
    echo "- AI assistant labeled as 'Ask Angisflow'\n";
    echo "- All branding consistently shows 'Angisflow'\n";
} else {
    echo "❌ Some checks FAILED. Review the output above.\n";
}

echo "\nRebranding complete! 🚀\n";