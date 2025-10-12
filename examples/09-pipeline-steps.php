<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pagent\Workflow\Pipeline;

// Example: Data extraction and transformation pipeline
// Extract JSON → Parse → Validate → Transform → Format

// Configure mock agents
$extractor = mock([
    'Invoice #INV-2025-001 for $2,500 due on 2025-02-15' => '{"invoice_number": "INV-2025-001", "amount": "2500", "due_date": "2025-02-15"}',
]);

$validator = mock([
    '{"invoice_number":"INV-2025-001","amount":2500,"due_date":"2025-02-15"}' => '{"valid": true, "errors": [], "warnings": ["Amount is above $1000 threshold"]}',
]);

$formatter = mock([
    '{"invoice_number":"INV-2025-001","amount":2500,"due_date":"2025-02-15","urgent":true}' => 'Invoice INV-2025-001: $2,500.00 due 2025-02-15 [URGENT]',
]);

// Create pipeline with named steps and transforms
$result = Pipeline::create()
    // Step 1: Extract structured data
    ->step('extract', $extractor)
    
    // Step 2: Parse JSON to array
    ->transform('parse', fn ($json) => json_decode($json, true))
    
    // Step 3: Add business logic (mark as urgent if amount > $1000)
    ->transform('enrich', fn ($data) => array_merge($data, [
        'urgent' => $data['amount'] > 1000,
    ]))
    
    // Step 4: Convert back to JSON for validation
    ->transform('to_json', fn ($data) => json_encode($data))
    
    // Step 5: Validate
    ->step('validate', $validator)
    
    // Step 6: Re-parse for formatting
    ->transform('reparse', fn ($json) => json_encode(json_decode($json, true)))
    
    // Step 7: Format for display
    ->step('format', $formatter)
    
    ->run('Invoice #INV-2025-001 for $2,500 due on 2025-02-15');

// Display results
echo "=== Pipeline: Invoice Processing ===\n\n";

echo "Final Output:\n";
echo $result->final . "\n\n";

echo "Step-by-Step Breakdown:\n";
echo "1. extract: " . $result->step('extract')->output . "\n";
echo "2. parse: " . json_encode($result->step('parse')->output) . "\n";
echo "3. enrich: " . json_encode($result->step('enrich')->output) . "\n";
echo "4. to_json: " . $result->step('to_json')->output . "\n";
echo "5. validate: " . $result->step('validate')->output . "\n";
echo "6. format: " . $result->step('format')->output . "\n\n";

// Access specific intermediate result
$parsedData = $result->step('parse')->output;
echo "Parsed Invoice Number: {$parsedData['invoice_number']}\n";
echo "Amount: \${$parsedData['amount']}\n\n";

// Check validation
$validation = $result->step('validate')->json();
echo "Validation Status: " . ($validation['valid'] ? 'PASSED' : 'FAILED') . "\n";
if (!empty($validation['warnings'])) {
    echo "Warnings:\n";
    foreach ($validation['warnings'] as $warning) {
        echo "  - $warning\n";
    }
}

echo "\nMetadata:\n";
echo "Total Tokens: {$result->meta->totalTokens}\n";
echo "Duration: " . round($result->meta->duration, 3) . "s\n";
echo "Steps Executed: {$result->meta->stepsExecuted}\n";
