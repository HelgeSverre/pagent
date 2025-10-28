<?php

// Multi-agent pipeline with automatic tool calling - stock analysis workflow
agent('analyst')->provider('anthropic')->system('Analyze stock data')
    ->tool('get_price', 'Get stock price', fn (string $symbol) => ['symbol' => $symbol, 'price' => rand(100, 500)])
    ->tool('get_volume', 'Get trading volume', fn (string $symbol) => ['volume' => rand(1000000, 10000000)]);

agent('validator')->provider('anthropic')->system('Validate and enrich data')
    ->tool('calculate_metrics', 'Calculate financial metrics', fn (array $data) => [...$data, 'pe_ratio' => rand(10, 30)]);

agent('reporter')->provider('openai')->system('Format as executive summary');

// Pipeline automatically calls tools across agents
$report = pipeline('stock-analysis')
    ->agent('analyst')
    ->agent('validator', fn ($data) => "Validate and enrich: {$data}")
    ->agent('reporter', fn ($data) => "Create executive summary from: {$data}")
    ->run('Analyze AAPL stock');

echo $report;
