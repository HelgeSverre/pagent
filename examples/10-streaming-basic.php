<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Pagent\Providers\Anthropic;

use function Pagent\agent;

// Example 1: Simple streaming with callback
echo "\n[Example 1: Simple Streaming]\n";

$agent = agent('streamer')
    ->provider(new Anthropic(['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')]))
    ->system('You are a helpful assistant. Be concise.')
    ->model('claude-sonnet-4-6')
    ->maxTokens(100);

echo "Input: Tell me a short joke about programming\n";
echo 'Output: ';

$agent->streamTo('Tell me a short joke about programming', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

echo "\n\n";

// Example 2: Manual streaming with more control
echo "\n[Example 2: Manual Stream Control]\n";

$agent2 = agent('manual-streamer')
    ->provider(new Anthropic(['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')]))
    ->model('claude-sonnet-4-6')
    ->maxTokens(150);

echo "Input: What is PHP?\n";
echo 'Output: ';

$streamResponse = $agent2->stream('What is PHP in one sentence?');

$chunkCount = 0;
$textChunks = 0;

foreach ($streamResponse->getStream() as $chunk) {
    $chunkCount++;

    if ($chunk->isStart()) {
        echo "Status: Stream started\n";
    }

    if ($chunk->isText()) {
        $textChunks++;
        echo $chunk->content;
        flush();
    }

    if ($chunk->isEnd()) {
        echo "\nStatus: Stream ended\n";
        echo "Chunks received: {$chunkCount}\n";
        echo "Text chunks: {$textChunks}\n";

        if ($chunk->getMetadata('usage')) {
            $usage = $chunk->getMetadata('usage');
            echo 'Tokens used: '.($usage['input_tokens'] ?? 0 + $usage['output_tokens'] ?? 0)."\n";
        }
    }
}

echo "\n\n";

// Example 3: Collecting full response
echo "\n[Example 3: Collect Full Response]\n";

$agent3 = agent('collector')
    ->provider(new Anthropic(['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')]))
    ->model('claude-sonnet-4-6')
    ->maxTokens(50);

$streamResponse = $agent3->stream('Say hello');
$fullContent = $streamResponse->collect();

echo "Output: {$fullContent}\n";
echo 'Provider: '.$streamResponse->getProvider()."\n";
echo 'Model: '.$streamResponse->getModel()."\n";

if ($streamResponse->getUsage()) {
    $usage = $streamResponse->getUsage();
    echo 'Input tokens: '.($usage['input_tokens'] ?? 0)."\n";
    echo 'Output tokens: '.($usage['output_tokens'] ?? 0)."\n";
}

echo "\nDone.\n";
