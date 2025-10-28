<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Pagent\Providers\Anthropic;

use function Pagent\agent;

// Example 1: Simple streaming with callback
echo "Example 1: Simple Streaming\n";
echo str_repeat('=', 50)."\n\n";

$agent = agent('streamer')
    ->provider(new Anthropic(['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')]))
    ->system('You are a helpful assistant. Be concise.')
    ->model('claude-3-haiku-20240307')
    ->maxTokens(100);

echo "Question: Tell me a short joke about programming\n";
echo 'Response: ';

$agent->streamTo('Tell me a short joke about programming', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

echo "\n\n";

// Example 2: Manual streaming with more control
echo "Example 2: Manual Stream Control\n";
echo str_repeat('=', 50)."\n\n";

$agent2 = agent('manual-streamer')
    ->provider(new Anthropic(['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')]))
    ->model('claude-3-haiku-20240307')
    ->maxTokens(150);

echo "Question: What is PHP?\n";
echo 'Response: ';

$streamResponse = $agent2->stream('What is PHP in one sentence?');

$chunkCount = 0;
$textChunks = 0;

foreach ($streamResponse->getStream() as $chunk) {
    $chunkCount++;

    if ($chunk->isStart()) {
        echo "[Stream Started]\n";
    }

    if ($chunk->isText()) {
        $textChunks++;
        echo $chunk->content;
        flush();
    }

    if ($chunk->isEnd()) {
        echo "\n[Stream Ended]\n";
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
echo "Example 3: Collect Full Response\n";
echo str_repeat('=', 50)."\n\n";

$agent3 = agent('collector')
    ->provider(new Anthropic(['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')]))
    ->model('claude-3-haiku-20240307')
    ->maxTokens(50);

$streamResponse = $agent3->stream('Say hello');
$fullContent = $streamResponse->collect();

echo "Full response: {$fullContent}\n";
echo 'Provider: '.$streamResponse->getProvider()."\n";
echo 'Model: '.$streamResponse->getModel()."\n";

if ($streamResponse->getUsage()) {
    $usage = $streamResponse->getUsage();
    echo 'Input tokens: '.($usage['input_tokens'] ?? 0)."\n";
    echo 'Output tokens: '.($usage['output_tokens'] ?? 0)."\n";
}

echo "\n";
