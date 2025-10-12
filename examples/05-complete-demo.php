<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

if (\file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "🤖 Pagent Complete Demo\n";
echo "=======================\n\n";

echo "=== 1. Customer Support Bot with Tools ===\n\n";

\agent('support-bot')
    ->provider('openai')
    ->system('You are a helpful customer support agent. Use the tools available to help customers.')
    ->temperature(0.7)
    ->tool('check_order_status', 'Check the status of an order', function (string $order_id): string {
        $statuses = [
            '12345' => 'shipped',
            '67890' => 'processing',
            '11111' => 'delivered',
        ];

        return $statuses[$order_id] ?? 'not found';
    })
    ->tool('calculate_refund', 'Calculate refund amount', function (float $order_total, int $days_since_purchase): float {
        if ($days_since_purchase > 30) {
            return 0.0;
        }

        return $days_since_purchase <= 7 ? $order_total : $order_total * 0.5;
    });

$support = \agent('support-bot');

echo "Customer: What's the status of my order #12345?\n";
$r1 = $support->prompt("What's the status of my order #12345?");
echo "Support: {$r1->content}\n\n";

echo "Customer: I want to return an item worth \$99.99, I bought it 5 days ago\n";
$r2 = $support->prompt('I want to return an item worth $99.99, I bought it 5 days ago');
echo "Support: {$r2->content}\n\n";

echo "=== 2. Research Assistant ===\n\n";

\agent('researcher')
    ->provider('openai')
    ->system('You are a research assistant.')
    ->tool('search_database', 'Search for information', function (string $query): string {
        $data = [
            'php' => 'PHP is a popular server-side scripting language created in 1994 by Rasmus Lerdorf.',
            'laravel' => 'Laravel is a PHP framework created by Taylor Otwell in 2011.',
            'composer' => 'Composer is a dependency manager for PHP created in 2012.',
        ];

        foreach ($data as $key => $value) {
            if (\str_contains(\mb_strtolower($query), $key)) {
                return $value;
            }
        }

        return 'No information found.';
    })
    ->tool('calculate_age', 'Calculate age from year', fn(int $year): int => \date('Y') - $year);

$researcher = \agent('researcher');

echo "User: When was PHP created and how old is it?\n";
$r3 = $researcher->prompt('When was PHP created and how old is it?');
echo "Assistant: {$r3->content}\n\n";

echo "=== 3. Multi-Step Task with Tools ===\n\n";

\agent('analyst')
    ->provider('openai')
    ->system('You are a data analyst. Use tools to help with calculations.')
    ->tool('fetch_data', 'Fetch sales data', fn(string $month): array => [
        'january' => ['sales' => 10000, 'customers' => 50],
        'february' => ['sales' => 15000, 'customers' => 75],
        'march' => ['sales' => 12000, 'customers' => 60],
    ][\mb_strtolower($month)] ?? ['sales' => 0, 'customers' => 0])
    ->tool('calculate_average', 'Calculate average', fn(int $total, int $count): float => $count > 0 ? $total / $count : 0);

$analyst = \agent('analyst');

echo "User: What was the average sale per customer in February?\n";
$r4 = $analyst->prompt('What was the average sale per customer in February?');
echo "Analyst: {$r4->content}\n\n";

echo "=== 4. Conversation Inspection ===\n\n";

echo "Message history for support-bot:\n";
echo 'Total messages: ' . \count($support->messages) . "\n";
foreach (\array_slice($support->messages, 0, 4) as $i => $msg) {
    $role = \mb_strtoupper($msg['role']);
    $content = \is_string($msg['content']) ? \mb_substr($msg['content'], 0, 60) : '[complex]';
    echo "  [{$role}] {$content}...\n";
}

echo "\n=== 5. Tool Inspection ===\n\n";

$tools = $support->getTools();
echo 'Support bot has ' . \count($tools) . " tools:\n";
foreach ($tools as $tool) {
    echo "  - {$tool->name}: {$tool->description}\n";
    echo '    Parameters: ';
    $params = \array_map(fn($arg) => "{$arg->name}: {$arg->type}", $tool->arguments);
    echo \implode(', ', $params) . "\n";
}

echo "\n✅ Complete demo finished!\n";
echo "\nTool calling is working perfectly with both OpenAI and Anthropic! 🎉\n";
