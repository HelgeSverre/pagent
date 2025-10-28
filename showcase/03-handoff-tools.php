<?php

// Intelligent agent handoff with context preservation and shared tools
agent('support')->provider('openai')->system('L1 support agent')
    ->tool('check_order', 'Check order status', fn (string $id) => ['id' => $id, 'status' => 'shipped', 'tracking' => 'TRK123'])
    ->tool('refund_small', 'Process refund under $100', function (string $id, float $amount) {
        return $amount > 100 ? ['error' => 'Exceeds limit'] : ['refund' => "REF-{$id}", 'approved' => true];
    });

agent('billing')->provider('anthropic')->system('Billing specialist')
    ->tool('refund_large', 'Process large refund', fn (string $id, float $amount) => ['refund' => "REF-LARGE-{$id}", 'amount' => $amount, 'manager_approval' => true])
    ->tool('adjust_subscription', 'Modify subscription', fn (string $id, string $plan) => ['subscription' => $id, 'new_plan' => $plan, 'effective' => date('Y-m-d')]);

agent('technical')->provider('anthropic')->system('Technical specialist')
    ->tool('run_diagnostics', 'Run system diagnostics', fn (string $issue) => ['tests' => rand(5, 15), 'failures' => rand(0, 2), 'fix' => 'Applied patch #'.rand(100, 999)]);

// Conversation with intelligent routing
$agent = agent('support');
$agent->prompt('I need a $150 refund for order #12345');

// Handoff preserves conversation context and transfers to specialist
$billing = $agent->handoff('billing', 'Large refund request');
$response = $billing->prompt('Process the refund now');

// Context is maintained
echo 'Messages: '.count($billing->messages).' | Response: '.$response->content;
