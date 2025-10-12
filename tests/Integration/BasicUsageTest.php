<?php

declare(strict_types=1);

it('demonstrates basic agent usage', function (): void {
    // Create and configure an agent
    agent('assistant')
        ->provider('mock', [
            'responses' => [
                'Hello' => 'Hi! How can I help you?',
                'What can you do?' => 'I can answer questions and have conversations.',
            ],
        ])
        ->system('You are a helpful assistant')
        ->temperature(0.7);

    // Use the agent
    $agent = agent('assistant');

    $response1 = $agent->prompt('Hello');
    expect($response1->content)->toBe('Hi! How can I help you?');

    $response2 = $agent->prompt('What can you do?');
    expect($response2->content)->toBe('I can answer questions and have conversations.');

    // Check conversation history
    expect($agent->messages)->toHaveCount(4); // 2 user + 2 assistant messages
});

it('demonstrates provider switching', function (): void {
    // Start with mock provider
    agent('flexible')
        ->provider('mock')
        ->system('Test agent');

    $agent = agent('flexible');
    $response = $agent->prompt('test');
    expect($response->provider)->toBe('mock');

    // Could switch to another provider in real usage
    // For testing, we'll create a new agent
    agent('flexible-2')
        ->provider('mock', ['responses' => ['test' => 'Different provider response']])
        ->system('Test agent 2');

    $agent2 = agent('flexible-2');
    $response2 = $agent2->prompt('test');
    expect($response2->content)->toBe('Different provider response');
});

it('demonstrates direct provider usage', function (): void {
    // Use provider directly without agent
    $mock = mock([
        'What is 2+2?' => '4',
        'What is the capital of France?' => 'Paris',
    ]);

    $response1 = $mock->prompt('What is 2+2?');
    expect($response1->content)->toBe('4');

    $response2 = $mock->prompt('What is the capital of France?');
    expect($response2->content)->toBe('Paris');
});

it('demonstrates multiple agents working together', function (): void {
    // Create specialized agents
    agent('translator')
        ->provider('mock', ['responses' => [
            'Translate: Hello' => 'Bonjour',
        ]])
        ->system('You are a translator');

    agent('analyzer')
        ->provider('mock', ['responses' => [
            'Analyze: Bonjour' => 'This is French for "Hello"',
        ]])
        ->system('You analyze text');

    // Use them together
    $translator = agent('translator');
    $analyzer = agent('analyzer');

    $translated = $translator->prompt('Translate: Hello');
    $analysis = $analyzer->prompt('Analyze: '.$translated->content);

    expect($analysis->content)->toBe('This is French for "Hello"');
});

it('lists all registered agents', function (): void {
    clearAgents(); // Start fresh

    agent('agent-1')->provider('mock');
    agent('agent-2')->provider('mock');
    agent('agent-3')->provider('mock');

    $all = agents();

    expect($all)->toHaveCount(3);
    expect(array_keys($all))->toBe(['agent-1', 'agent-2', 'agent-3']);
});
