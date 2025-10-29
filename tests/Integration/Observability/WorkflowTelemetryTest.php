<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Observability\Exporters\InMemoryExporter;
use Pagent\Observability\TelemetryManager;
use Pagent\Providers\Mock;
use Pagent\Workflow\Chain;
use Pagent\Workflow\Pipeline;

beforeEach(function () {
    // Reset telemetry between tests
    TelemetryManager::reset();

    // Create in-memory exporter for testing
    $this->exporter = new InMemoryExporter;

    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'service_name' => 'pagent-test',
        'exporter' => 'console',
        'verbose' => false,
    ])->setExporter($this->exporter);
});

afterEach(function () {
    TelemetryManager::reset();
});

test('it traces multi-step pipeline with telemetry enabled', function () {
    // Create mock agents with telemetry enabled
    $agent1 = new Agent('researcher');
    $agent1->provider(new Mock(['responses' => ['Research input' => 'Research result']]));
    $agent1->telemetry(true);

    $agent2 = new Agent('writer');
    $agent2->provider(new Mock(['responses' => ['Research result' => 'Written content']]));
    $agent2->telemetry(true);

    $agent3 = new Agent('editor');
    $agent3->provider(new Mock(['responses' => ['Written content' => 'Edited final content']]));
    $agent3->telemetry(true);

    // Create pipeline
    $pipeline = Pipeline::create('test-pipeline')
        ->step('research', $agent1)
        ->step('write', $agent2)
        ->step('edit', $agent3);

    // Run pipeline
    $result = $pipeline->run('Research input');

    // Verify result
    expect($result->final)->toBe('Edited final content');
    expect($result->steps)->toHaveCount(3);
    expect($result->meta->stepsExecuted)->toBe(3);

    TelemetryManager::instance()->shutdown();

    // Verify pipeline span was created
    $pipelineSpans = $this->exporter->findSpansByName('workflow.pipeline.run');
    expect($pipelineSpans)->toHaveCount(1);

    $pipelineSpan = $pipelineSpans[0];
    expect($pipelineSpan->getAttributes()->get('workflow.name'))->toBe('test-pipeline');
    expect($pipelineSpan->getAttributes()->get('workflow.type'))->toBe('pipeline');
    expect($pipelineSpan->getAttributes()->get('workflow.steps_executed'))->toBe(3);

    // Verify step spans were created
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(3);

    // Verify step attributes
    expect($stepSpans[0]->getAttributes()->get('step.name'))->toBe('research');
    expect($stepSpans[1]->getAttributes()->get('step.name'))->toBe('write');
    expect($stepSpans[2]->getAttributes()->get('step.name'))->toBe('edit');

    // Verify agent spans were created (3 prompts for 3 agents)
    $agentSpans = $this->exporter->findSpansByName('agent.prompt');
    expect($agentSpans)->toHaveCount(3);
});

test('it links parent-child spans correctly in pipeline', function () {
    // Create agents with telemetry
    $agent1 = new Agent('step1');
    $agent1->provider(new Mock(['responses' => ['input' => 'output1']]));
    $agent1->telemetry(true);

    $agent2 = new Agent('step2');
    $agent2->provider(new Mock(['responses' => ['output1' => 'output2']]));
    $agent2->telemetry(true);

    // Create pipeline
    $pipeline = Pipeline::create('span-test-pipeline')
        ->step('first', $agent1)
        ->step('second', $agent2);

    // Run pipeline
    $result = $pipeline->run('input');

    // Verify result
    expect($result->final)->toBe('output2');
    expect($result->steps)->toHaveCount(2);

    TelemetryManager::instance()->shutdown();

    // Verify span hierarchy
    $spans = $this->exporter->getSpans();
    expect($spans)->not->toBeEmpty();

    // Find pipeline span
    $pipelineSpans = $this->exporter->findSpansByName('workflow.pipeline.run');
    expect($pipelineSpans)->toHaveCount(1);

    $pipelineSpan = $pipelineSpans[0];
    $pipelineSpanId = $pipelineSpan->getContext()->getSpanId();

    // Verify step spans are children of pipeline span
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    foreach ($stepSpans as $stepSpan) {
        expect($stepSpan->getParentContext()->getSpanId())->toBe($pipelineSpanId);
    }
});

test('it records step failures in pipeline', function () {
    // Create agent that will fail
    $agent1 = new Agent('failing-agent');
    $agent1->provider(new Mock);
    $agent1->telemetry(true);

    // Create agent that throws exception
    $agent2 = new Agent('exception-agent');
    $mockProvider = new class implements Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new RuntimeException('Intentional failure for testing');
        }
    };
    $agent2->provider($mockProvider);
    $agent2->telemetry(true);

    // Create pipeline
    $pipeline = Pipeline::create('failing-pipeline')
        ->step('first', $agent1)
        ->step('second', $agent2);

    // Expect exception to be thrown
    try {
        $pipeline->run('test input');
        $this->fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Intentional failure for testing');
    }

    TelemetryManager::instance()->shutdown();

    // Verify pipeline span was created with error
    $pipelineSpans = $this->exporter->findSpansByName('workflow.pipeline.run');
    expect($pipelineSpans)->toHaveCount(1);

    $pipelineSpan = $pipelineSpans[0];
    expect($pipelineSpan->getStatus()->getCode())->toBe(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);

    // Verify step spans were created
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(2);

    // Second step should have error status
    expect($stepSpans[1]->getStatus()->getCode())->toBe(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
});

test('it includes step metadata in pipeline spans', function () {
    // Create agents with different token usage
    $agent1 = new Agent('agent1');
    $mockProvider1 = new Mock;
    $mockProvider1->setResponse('input', 'output1');
    $agent1->provider($mockProvider1);
    $agent1->telemetry(true);

    $agent2 = new Agent('agent2');
    $mockProvider2 = new Mock;
    $mockProvider2->setResponse('output1', 'output2');
    $agent2->provider($mockProvider2);
    $agent2->telemetry(true);

    // Create pipeline
    $pipeline = Pipeline::create('metadata-pipeline')
        ->step('first_step', $agent1)
        ->step('second_step', $agent2);

    // Run pipeline
    $result = $pipeline->run('input');

    // Verify step metadata
    expect($result->steps)->toHaveCount(2);
    expect($result->steps[0]->name)->toBe('first_step');
    expect($result->steps[0]->meta->duration)->toBeGreaterThan(0);
    expect($result->steps[1]->name)->toBe('second_step');
    expect($result->steps[1]->meta->duration)->toBeGreaterThan(0);

    TelemetryManager::instance()->shutdown();

    // Verify step spans have duration
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(2);

    foreach ($stepSpans as $stepSpan) {
        expect($stepSpan->getStartEpochNanos())->toBeGreaterThan(0);
        expect($stepSpan->getEndEpochNanos())->toBeGreaterThan($stepSpan->getStartEpochNanos());
    }

    // Verify workflow metadata
    $pipelineSpan = $this->exporter->findSpansByName('workflow.pipeline.run')[0];
    expect($pipelineSpan->getAttributes()->get('workflow.steps_executed'))->toBe(2);
});

test('it works with transform steps in pipeline', function () {
    // Create agent
    $agent1 = new Agent('agent1');
    $agent1->provider(new Mock(['responses' => ['INPUT' => 'agent output']]));
    $agent1->telemetry(true);

    // Create pipeline with transform step
    $pipeline = Pipeline::create('transform-pipeline')
        ->transform('uppercase', fn ($input) => strtoupper($input))
        ->step('process', $agent1)
        ->transform('lowercase', fn ($input) => strtolower($input));

    // Run pipeline
    $result = $pipeline->run('input');

    // Verify transforms were applied
    expect($result->final)->toBe('agent output');
    expect($result->steps)->toHaveCount(3);

    TelemetryManager::instance()->shutdown();

    // Verify transform spans were created
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(3);

    // Verify step types
    expect($stepSpans[0]->getAttributes()->get('step.type'))->toBe('transform');
    expect($stepSpans[1]->getAttributes()->get('step.type'))->toBe('agent');
    expect($stepSpans[2]->getAttributes()->get('step.type'))->toBe('transform');
});

test('it works when telemetry is disabled', function () {
    // Create agents WITHOUT telemetry
    $agent1 = new Agent('agent1');
    $agent1->provider(new Mock(['responses' => ['input' => 'output1']]));

    $agent2 = new Agent('agent2');
    $agent2->provider(new Mock(['responses' => ['output1' => 'output2']]));

    // Create pipeline
    $pipeline = Pipeline::create('no-telemetry-pipeline')
        ->step('first', $agent1)
        ->step('second', $agent2);

    // Run pipeline
    $result = $pipeline->run('input');

    // Verify result is correct even without telemetry
    expect($result->final)->toBe('output2');
    expect($result->steps)->toHaveCount(2);

    TelemetryManager::instance()->shutdown();

    // Verify NO workflow spans were created (agents don't have telemetry enabled)
    $spans = $this->exporter->getSpans();
    expect($spans)->toBeEmpty();
});

test('it traces chain workflow with telemetry', function () {
    // Create agents for chain
    $agent1 = new Agent('chain1');
    $agent1->provider(new Mock(['responses' => ['start' => 'step1']]));
    $agent1->telemetry(true);

    $agent2 = new Agent('chain2');
    $agent2->provider(new Mock(['responses' => ['step1' => 'step2']]));
    $agent2->telemetry(true);

    $agent3 = new Agent('chain3');
    $agent3->provider(new Mock(['responses' => ['step2' => 'final']]));
    $agent3->telemetry(true);

    // Create chain
    $chain = Chain::create('test-chain')
        ->add($agent1)
        ->add($agent2)
        ->add($agent3);

    // Run chain
    $result = $chain->run('start');

    // Verify result
    expect($result->final)->toBe('final');
    expect($result->steps)->toHaveCount(3);
    expect($result->meta->stepsExecuted)->toBe(3);

    TelemetryManager::instance()->shutdown();

    // Verify chain span was created
    $chainSpans = $this->exporter->findSpansByName('workflow.chain.run');
    expect($chainSpans)->toHaveCount(1);

    $chainSpan = $chainSpans[0];
    expect($chainSpan->getAttributes()->get('workflow.name'))->toBe('test-chain');
    expect($chainSpan->getAttributes()->get('workflow.type'))->toBe('chain');
    expect($chainSpan->getAttributes()->get('workflow.steps_executed'))->toBe(3);

    // Verify step spans
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(3);
});

test('it records chain failures with telemetry', function () {
    // Create agents
    $agent1 = new Agent('chain-agent1');
    $agent1->provider(new Mock(['responses' => ['input' => 'output1']]));
    $agent1->telemetry(true);

    // Create failing agent
    $agent2 = new Agent('chain-failing-agent');
    $mockProvider = new class implements Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new RuntimeException('Chain failure test');
        }
    };
    $agent2->provider($mockProvider);
    $agent2->telemetry(true);

    // Create chain
    $chain = Chain::create('failing-chain')
        ->add($agent1)
        ->add($agent2);

    // Expect exception
    try {
        $chain->run('input');
        $this->fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Chain failure test');
    }

    TelemetryManager::instance()->shutdown();

    // Verify chain span has error status
    $chainSpans = $this->exporter->findSpansByName('workflow.chain.run');
    expect($chainSpans)->toHaveCount(1);
    expect($chainSpans[0]->getStatus()->getCode())->toBe(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);

    // Verify step spans
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(2);

    // Second step should have error
    expect($stepSpans[1]->getStatus()->getCode())->toBe(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
});

test('it works with mixed telemetry settings in pipeline', function () {
    // First agent with telemetry enabled
    $agent1 = new Agent('with-telemetry');
    $agent1->provider(new Mock(['responses' => ['input' => 'output1']]));
    $agent1->telemetry(true);

    // Second agent without telemetry
    $agent2 = new Agent('without-telemetry');
    $agent2->provider(new Mock(['responses' => ['output1' => 'output2']]));
    // telemetry NOT enabled

    // Create pipeline - should enable telemetry because agent1 has it
    $pipeline = Pipeline::create('mixed-telemetry-pipeline')
        ->step('first', $agent1)
        ->step('second', $agent2);

    // Run pipeline
    $result = $pipeline->run('input');

    // Verify result
    expect($result->final)->toBe('output2');
    expect($result->steps)->toHaveCount(2);

    TelemetryManager::instance()->shutdown();

    // Verify workflow spans were created (at least one agent has telemetry)
    $pipelineSpans = $this->exporter->findSpansByName('workflow.pipeline.run');
    expect($pipelineSpans)->toHaveCount(1);

    // Verify step spans
    $stepSpans = $this->exporter->findSpansByName('workflow.step');
    expect($stepSpans)->toHaveCount(2);

    // Verify only first agent created agent spans
    $agentSpans = $this->exporter->findSpansByName('agent.prompt');
    expect($agentSpans)->toHaveCount(1); // Only agent1 has telemetry
    expect($agentSpans[0]->getAttributes()->get('agent.name'))->toBe('with-telemetry');
});

test('it verifies workflow span timing', function () {
    $agent1 = new Agent('timing1');
    $agent1->provider(new Mock(['responses' => ['input' => 'output']]));
    $agent1->telemetry(true);

    $pipeline = Pipeline::create('timing-test')
        ->step('test', $agent1);

    $result = $pipeline->run('input');
    expect($result->final)->toBe('output');

    TelemetryManager::instance()->shutdown();

    // Verify all spans have valid timing
    $spans = $this->exporter->getSpans();
    expect($spans)->not->toBeEmpty();

    foreach ($spans as $span) {
        $duration = $span->getEndEpochNanos() - $span->getStartEpochNanos();
        expect($duration)->toBeGreaterThan(0);
    }
});

test('it records workflow input and output in spans', function () {
    $agent1 = new Agent('io-test');
    $agent1->provider(new Mock(['responses' => ['test input' => 'test output']]));
    $agent1->telemetry(true);

    $pipeline = Pipeline::create('io-pipeline')
        ->step('process', $agent1);

    $result = $pipeline->run('test input');
    expect($result->final)->toBe('test output');

    TelemetryManager::instance()->shutdown();

    // Verify pipeline span has input/output
    $pipelineSpans = $this->exporter->findSpansByName('workflow.pipeline.run');
    expect($pipelineSpans)->toHaveCount(1);

    $pipelineSpan = $pipelineSpans[0];
    expect($pipelineSpan->getAttributes()->get('workflow.input'))->toBe('test input');
    expect($pipelineSpan->getAttributes()->get('workflow.output'))->toBe('test output');
});
