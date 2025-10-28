<?php

use Pagent\Middleware\MetricsMiddleware;

// Secure pipeline with guards, middleware, and tools
$metrics = new MetricsMiddleware;

agent('extractor')->provider('openai')->system('Extract data from text')
    ->guard('pii')->guard('promptInjection')->fallback(fn () => 'Blocked')
    ->middleware($metrics)
    ->tool('extract_json', 'Extract structured data', fn (string $text) => json_encode(['extracted' => true, 'fields' => rand(3, 10)]));

agent('validator')->provider('anthropic')->system('Validate data quality')
    ->guard('contentFilter')->middleware($metrics)
    ->tool('validate_schema', 'Validate against schema', fn (string $data) => ['valid' => true, 'errors' => 0, 'warnings' => rand(0, 2)]);

agent('enricher')->provider('openai')->system('Enrich with metadata')
    ->middleware($metrics)
    ->tool('add_metadata', 'Add timestamps and ids', fn (string $data) => json_encode(['id' => uniqid(), 'processed_at' => date('c'), 'data' => $data]));

// Secure pipeline with error recovery
$result = pipeline('secure-data-processing')
    ->agent('extractor')
    ->agent('validator', fn ($d) => "Validate: {$d}")
    ->agent('enricher', fn ($d) => "Enrich: {$d}")
    ->onError(fn ($e, $stage) => "Recovered at stage {$stage}")
    ->run('Extract data from: John Smith, email: john@example.com, SSN: 123-45-6789');

echo "Result: {$result}\nTotal requests: ".count($metrics->getMetrics());
