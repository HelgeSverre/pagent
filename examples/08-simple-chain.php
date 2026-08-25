<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Pagent\Workflow\Chain;

// Example: Invoice processing chain
// Step 1: Extract data → Step 2: Validate → Step 3: Approve

// Configure specialized agents with mock responses
$extractor = mock([
    'Invoice #12345, Amount: $1,500.00, Date: 2025-01-12' => '{"number": "12345", "amount": 1500, "date": "2025-01-12"}',
]);

$validator = mock([
    '{"number": "12345", "amount": 1500, "date": "2025-01-12"}' => '{"valid": true, "errors": [], "warnings": []}',
]);

$approver = mock([
    '{"valid": true, "errors": [], "warnings": []}' => '{"approved": true, "assigned_to": "finance_team", "priority": "normal"}',
]);

// Create the workflow chain
$result = Chain::create()
    ->add($extractor)
    ->add($validator)
    ->add($approver)
    ->run('Invoice #12345, Amount: $1,500.00, Date: 2025-01-12');

// Output results
echo "\n[Invoice Processing Chain]\n";

echo "Final Output:\n";
echo $result->final."\n\n";

echo "Step-by-Step Results:\n";
foreach ($result->steps as $step) {
    echo "- {$step->name}: {$step->output}\n";
}

echo "\nMetadata:\n";
echo "Total Tokens: {$result->meta->totalTokens}\n";
echo 'Duration: '.round($result->meta->duration, 3)."s\n";
echo "Steps Executed: {$result->meta->stepsExecuted}\n";

// Export for logging
$export = $result->export();
echo "\n[Full Export]\n";
echo json_encode($export, JSON_PRETTY_PRINT)."\n";
