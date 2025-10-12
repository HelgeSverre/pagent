<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "🤝 Pagent Multi-Agent Orchestration Demo\n";
echo "=========================================\n\n";

echo "=== Example 1: Pipeline (Sequential Agents) ===\n\n";

agent('silly')
    ->provider('openai')
    ->system(
        'You at randomy will swap out a word with a broken unicode character. '
        . 'followed by a upside down emoji, nobody knows why.',
    );

agent('extractor')
    ->provider('openai')
    ->system('You extract key information from text. Output only the extracted data in one line.');

agent('validator')
    ->provider('openai')
    ->system('You validate and correct data. Output only the validated data in one line.');

agent('formatter')
    ->provider('openai')
    ->system('You format data nicely. Output only the final formatted result.');

echo "Processing document through 3-agent pipeline...\n\n";

$document = "John Doe, age 30, lives in Oslo, Norway. Email: john@example.com";

$result = pipeline('document-processor')
    ->agent('extractor')
    ->agent('validator')
    ->agent('silly')
    ->agent('formatter')
    ->run($document);

echo "Input: {$document}\n";
echo "Pipeline output: {$result}\n\n";

echo "=== Example 2: Agent Handoff ===\n\n";

agent('general-support')
    ->provider('openai')
    ->system('You are a general customer support agent. If you receive legal/technical questions, say you need to transfer.');

agent('legal-expert')
    ->provider('openai')
    ->system('You are a legal expert. Provide concise legal guidance.');

$support = agent('general-support');

echo "Customer: I have a question about your terms of service\n";
$response1 = $support->prompt('I have a question about your terms of service and privacy policy');
echo "Support: {$response1->content}\n\n";

// Handoff to legal expert
echo "Transferring to legal expert...\n\n";
$legalAgent = $support->handoff('legal-expert', 'Customer has legal questions');

echo "Legal Expert: Now handling your legal questions\n";
$response2 = $legalAgent->prompt('Can you help me understand the refund policy?');
echo "Legal: {$response2->content}\n\n";

echo "=== Example 3: Delegation (Manager → Worker) ===\n\n";

agent('project-manager')
    ->provider('openai')
    ->system('You are a project manager who reviews work. Be brief.');

agent('developer')
    ->provider('openai')
    ->system('You are a senior developer. Write concise code.');

$manager = agent('project-manager');

echo "Manager delegates task to developer...\n\n";

$result = $manager->delegate('Write a PHP function that adds two numbers')
    ->to('developer')
    ->to('silly')
    ->supervise(function ($output, $task): bool {
        echo "  [Supervisor] Reviewing work...\n";

        return str_contains($output, 'function');
    })
    ->onComplete(function ($result): void {
        echo "  [Complete] Task finished!\n";
    })
    ->execute();

echo "Task: {$result->task}\n";
echo "Worker: {$result->worker}\n";
echo "Output: " . mb_substr($result->worker_output, 0, 100) . "...\n";
echo "Manager Review: {$result->manager_review}\n\n";

echo "=== Example 4: Pipeline with Transform ===\n\n";

agent('analyzer')
    ->provider('openai')
    ->system('Analyze text and return sentiment: positive, negative, or neutral. One word only.');

agent('reporter')
    ->provider('openai')
    ->system('Create a brief summary report.');

$sentimentResult = pipeline('sentiment-analysis')
    ->agent('analyzer')
    ->agent('reporter', fn($sentiment) => "The sentiment was: {$sentiment}. Provide a one-sentence summary.")
    ->run('This product is amazing! I love it!');

echo "Pipeline with transform: {$sentimentResult}\n\n";

echo "=== Example 5: Pipeline with Error Recovery ===\n\n";

agent('step1')->provider('openai')->system('Step 1');
agent('step2')->provider('openai')->system('Step 2');

$safeResult = pipeline('safe-pipeline')
    ->agent('step1')
    ->agent('step2')
    ->onError(fn($error, $stage, $agentName) => "Pipeline failed at stage {$stage} ({$agentName}). Recovered gracefully.")
    ->run('Test input');

echo "Safe pipeline result: " . mb_substr($safeResult, 0, 80) . "...\n\n";

echo "=== Example 6: Complex Multi-Agent Workflow ===\n\n";

agent('customer-support')
    ->provider('openai')
    ->system('You are customer support. Route complex issues.');

agent('technical-expert')
    ->provider('openai')
    ->system('You are a technical expert.');

agent('billing-specialist')
    ->provider('openai')
    ->system('You are a billing specialist.');

$support = agent('customer-support');

echo "Customer: Why am I being charged twice?\n";
$response = $support->prompt('Why am I being charged twice on my account?');
echo "Support: " . mb_substr($response->content, 0, 80) . "...\n\n";

echo "Handing off to billing specialist...\n";
$billing = $support->handoff('billing-specialist', 'Billing inquiry');
$billingResponse = $billing->prompt('Please explain the charges');
echo "Billing: " . mb_substr($billingResponse->content, 0, 80) . "...\n\n";

echo "✅ All multi-agent examples completed!\n";
echo "\n🤝 Multi-agent orchestration enables complex workflows!\n";
