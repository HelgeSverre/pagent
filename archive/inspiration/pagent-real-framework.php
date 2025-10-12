<?php

/**
 * Pagent - A Real LLM Agent Framework
 * 
 * Leveraging Pest's elegant syntax for building, testing, and deploying LLM agents
 */

use Pagent\Agent;
use Pagent\Tools\{WebSearch, Calculator, Database, FileSystem};
use Pagent\Providers\{OpenAI, Anthropic, Local};
use function Pagent\{agent, prompt, chain, expect};

/*
|--------------------------------------------------------------------------
| Agent Definition
|--------------------------------------------------------------------------
| Define agents with clear capabilities and constraints
*/

agent('customer-support')
    ->provider(OpenAI::class, ['model' => 'gpt-4-turbo'])
    ->systemPrompt('You are a helpful customer support agent...')
    ->temperature(0.7)
    ->maxTokens(500)
    ->tools([WebSearch::class, Database::class])
    ->middleware([
        'rate_limit' => 100,
        'content_filter' => true,
        'logging' => true
    ]);

/*
|--------------------------------------------------------------------------
| Behavioral Tests
|--------------------------------------------------------------------------
| Test agent behaviors with real examples
*/

it('handles refund requests appropriately', function () {
    $response = agent('customer-support')
        ->withContext(['order_id' => '12345', 'amount' => 99.99])
        ->prompt('I want a refund for my order');
    
    expect($response)
        ->toBeString()
        ->toContain(['refund', 'process'])
        ->toMatchTone('professional')
        ->not->toMakeUnauthorizedPromises();
});

it('escalates complex issues', function () {
    $response = agent('customer-support')
        ->prompt('I need to speak to legal about your terms of service');
    
    expect($response)
        ->toSuggestEscalation()
        ->toProvideTicketNumber()
        ->responseMetadata()->escalation_required->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Chain of Thought Patterns
|--------------------------------------------------------------------------
| Define multi-step reasoning chains
*/

chain('research-assistant')
    ->step('understand_query', function ($input) {
        return prompt("Extract key concepts from: {$input}")
            ->outputFormat(['concepts' => 'array', 'intent' => 'string']);
    })
    ->step('search', function ($previous) {
        return collect($previous->concepts)
            ->map(fn($concept) => WebSearch::query($concept))
            ->flatten();
    })
    ->step('synthesize', function ($results, $original) {
        return prompt("Synthesize this information...")
            ->withContext(['results' => $results, 'query' => $original])
            ->outputFormat('markdown');
    })
    ->validate(function ($output) {
        expect($output)
            ->toHaveCitations()
            ->toBeFactual()
            ->lengthToBeGreaterThan(200);
    });

/*
|--------------------------------------------------------------------------
| Tool Integration
|--------------------------------------------------------------------------
| Define how agents interact with external tools
*/

agent('data-analyst')
    ->tool(Database::class)
        ->forQueries(['SELECT', 'SHOW'])
        ->withTimeout(30)
        ->beforeQuery(function ($sql) {
            // Validate SQL safety
            expect($sql)->not->toContain(['DROP', 'DELETE', 'UPDATE']);
        })
    ->tool(Calculator::class)
        ->forOperations(['basic', 'statistical'])
        ->withPrecision(4);

it('generates accurate reports from database', function () {
    $report = agent('data-analyst')
        ->prompt('Show me sales trends for last quarter')
        ->usingTools([Database::class, Calculator::class]);
    
    expect($report)
        ->toContainDataVisualization()
        ->toHaveNumericAccuracy(0.99)
        ->queriesExecuted()->toBeArray();
});

/*
|--------------------------------------------------------------------------
| Context Management
|--------------------------------------------------------------------------
| Manage conversation context and memory
*/

describe('conversation management', function () {
    beforeEach(function () {
        $this->conversation = agent('assistant')
            ->withMemory('redis')
            ->sessionId('user-123');
    });
    
    it('maintains context across messages', function () {
        $this->conversation->prompt('My name is Alice');
        $response = $this->conversation->prompt('What is my name?');
        
        expect($response)->toContain('Alice');
        expect($this->conversation->memory()->get('user_name'))->toBe('Alice');
    });
    
    it('implements sliding window context', function () {
        $this->conversation->contextWindow(4000);
        
        // Add multiple messages
        foreach (range(1, 10) as $i) {
            $this->conversation->prompt("Message {$i}");
        }
        
        expect($this->conversation->activeContext())
            ->tokenCount()->toBeLessThan(4000)
            ->messageCount()->toBeLessThan(10);
    });
});

/*
|--------------------------------------------------------------------------
| Evaluation Framework
|--------------------------------------------------------------------------
| Systematic evaluation of agent performance
*/

evaluate('customer-support')
    ->dataset('support_tickets.csv')
    ->metrics([
        'helpfulness' => fn($response) => // Custom evaluation logic
        'accuracy' => 'semantic_similarity',
        'tone' => 'sentiment_analysis'
    ])
    ->benchmark([
        'response_time' => '<2s',
        'token_usage' => '<500',
        'success_rate' => '>0.95'
    ])
    ->export('reports/evaluation.json');

/*
|--------------------------------------------------------------------------
| Safety and Moderation
|--------------------------------------------------------------------------
| Implement safety rails and content moderation
*/

agent('public-facing')
    ->guard('content_filter', function ($input, $output) {
        expect($output)
            ->not->toContainPII()
            ->not->toContainHarmfulContent()
            ->not->toViolatePolicies(['medical_advice', 'legal_advice']);
    })
    ->guard('prompt_injection', function ($input) {
        expect($input)
            ->not->toContainSystemPromptOverride()
            ->not->toContainKnownExploits();
    })
    ->fallback(function ($error) {
        return "I apologize, but I cannot process that request.";
    });

/*
|--------------------------------------------------------------------------
| A/B Testing Agents
|--------------------------------------------------------------------------
| Test different agent configurations
*/

ab('support-bot-temperature')
    ->variant('conservative', fn() => agent('support')->temperature(0.3))
    ->variant('balanced', fn() => agent('support')->temperature(0.7))
    ->variant('creative', fn() => agent('support')->temperature(0.9))
    ->metric('customer_satisfaction')
    ->traffic(0.33, 0.33, 0.34)
    ->duration('7 days')
    ->analyze();

/*
|--------------------------------------------------------------------------
| Multi-Agent Collaboration
|--------------------------------------------------------------------------
| Orchestrate multiple agents working together
*/

pipeline('document-processor')
    ->agent('extractor', function ($document) {
        return prompt("Extract key information from this document")
            ->outputFormat('json');
    })
    ->agent('validator', function ($extracted) {
        return prompt("Validate and correct this data")
            ->withRules(['email' => 'email', 'phone' => 'regex']);
    })
    ->agent('formatter', function ($validated) {
        return prompt("Format this for the database")
            ->outputFormat('sql_insert');
    })
    ->onError(function ($error, $stage) {
        log("Pipeline failed at {$stage}: {$error}");
        return $this->fallbackAgent->handle($error);
    });

/*
|--------------------------------------------------------------------------
| Real-time Monitoring
|--------------------------------------------------------------------------
| Monitor agent performance in production
*/

monitor('all-agents')
    ->track([
        'response_time',
        'token_usage',
        'error_rate',
        'user_satisfaction'
    ])
    ->alert('high_error_rate', function ($metric) {
        return $metric->error_rate > 0.05;
    })
    ->alert('slow_response', function ($metric) {
        return $metric->p95_response_time > 3000; // ms
    })
    ->dashboard('http://localhost:3000/pagent');

/*
|--------------------------------------------------------------------------
| Deployment Configuration
|--------------------------------------------------------------------------
| Deploy agents with proper configuration
*/

deploy('production')
    ->agent('customer-support')
    ->replicas(3)
    ->loadBalancer('round-robin')
    ->healthCheck(function ($agent) {
        $response = $agent->prompt('Hello');
        expect($response)->toBeString()->lengthToBeGreaterThan(0);
    })
    ->rollbackStrategy('canary', [
        'initial' => '10%',
        'increment' => '20%',
        'interval' => '5m'
    ]);

/*
|--------------------------------------------------------------------------
| Cost Optimization
|--------------------------------------------------------------------------
| Track and optimize token usage
*/

optimize('token-usage')
    ->agent('*')
    ->cache('semantic', ['ttl' => 3600])
    ->compress('conversation_history')
    ->truncate('system_prompt', ['max_tokens' => 500])
    ->report('daily', function ($usage) {
        return [
            'total_tokens' => $usage->sum('tokens'),
            'cost_usd' => $usage->sum('cost'),
            'cache_hit_rate' => $usage->cacheHitRate()
        ];
    });

/*
|--------------------------------------------------------------------------
| Integration Tests
|--------------------------------------------------------------------------
| Test complete workflows
*/

it('completes full customer journey', function () {
    $session = createSession();
    
    // Initial contact
    $greeting = $session->agent('support')->prompt('Hi, I have a problem');
    expect($greeting)->toBeWelcoming();
    
    // Problem description
    $issue = $session->prompt('My order never arrived');
    expect($issue)->toRequestOrderNumber();
    
    // Provide information
    $details = $session->prompt('Order #12345');
    expect($details)
        ->toAccessDatabase()
        ->toProvideOrderStatus()
        ->toOfferSolution();
    
    // Verify tools were used correctly
    expect($session->toolCalls())
        ->toHaveCount(1)
        ->first()->tool->toBe('Database');
});

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
| Extend Pest's expectation API for LLM-specific assertions
*/

expect()->extend('toBeFactual', function () {
    $factChecker = new FactChecker();
    $score = $factChecker->verify($this->value);
    
    expect($score)->toBeGreaterThan(0.9);
});

expect()->extend('toHaveCitations', function () {
    $pattern = '/\[\d+\]|\(https?:\/\/[^\)]+\)/';
    expect($this->value)->toMatch($pattern);
});

/*
|--------------------------------------------------------------------------
| Continuous Learning
|--------------------------------------------------------------------------
| Implement feedback loops for improvement
*/

feedback('customer-support')
    ->collect('thumbs_up', 'thumbs_down')
    ->store('feedback_dataset')
    ->retrain(['threshold' => 1000, 'schedule' => 'weekly'])
    ->evaluate(['before', 'after'])
    ->notify('team@company.com');