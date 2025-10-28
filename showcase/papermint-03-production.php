<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Pagent\Tools\DataExtract;
use Pagent\Tools\PdfReader;

$receiptSchema = [
    'type' => 'object',
    'properties' => [
        'vendor' => ['type' => 'string'],
        'date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
        'amount' => ['type' => 'number'],
        'currency' => ['type' => 'string', 'enum' => ['USD', 'EUR', 'GBP', 'CAD']],
    ],
    'required' => ['vendor', 'date', 'amount'],
];

agent('extractor')->provider('openai')
    ->tool(new PdfReader(baseDir: '/receipts'))
    ->tool(new DataExtract(model: 'gpt-4o-mini'))
    ->system('Extract text from PDF and parse receipt data using tools');

agent('fact-checker')->provider('anthropic')->model('claude-3-haiku-20241022')
    ->system('Verify extracted fields exist in source text. Return VALID or list missing fields.');

agent('formatter')->provider('openai')->model('gpt-4o-mini')
    ->system('Format filename as: YYYY.MM.DD - VENDOR - AMOUNT.pdf');

$filename = pipeline('receipt-processor')
    ->agent('extractor')
    ->agent('fact-checker', fn ($data) => "Validate this data: {$data}")
    ->agent('formatter', fn ($validated) => "Generate filename from: {$validated}")
    ->onError(fn ($e, $stage) => sprintf('ERROR_%s_%d.pdf', strtoupper($stage), time()))
    ->run('/receipts/inbox/costco-receipt.pdf');

echo "Generated: {$filename}";
