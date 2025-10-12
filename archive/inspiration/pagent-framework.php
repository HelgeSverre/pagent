<?php

declare(strict_types=1);

/**
 * Pagent - When PestPHP decided to become an LLM Agent Framework
 *
 * "Because if you can test code, why not test reality?"
 */

use function Pagent\Agent\{agent, expect, mock, prompt};
use function Pagent\Memory\{context, forget, remember};
use function Pagent\Tools\{tool};

/*
|--------------------------------------------------------------------------
| Agent Configuration
|--------------------------------------------------------------------------
| Configure your AI agents using the familiar Pest syntax, because
| why learn something new when you can repurpose testing patterns?
*/

// Define a basic agent
agent('customer support bot', function (): void {
    expect($this)->toBeHelpful()
        ->toHavePatience()
        ->not->toHallucinateWildly();
});

// The classic 'it' syntax for agent behaviors
it('responds to customer complaints', function (): void {
    $response = prompt('My order never arrived!');

    expect($response)
        ->toContainEmpathy()
        ->toOfferSolution()
        ->not->toBeSarcastic();
})->with([
    'angry customer',
    'confused customer',
    'Karen who wants to speak to the manager',
]);

/*
|--------------------------------------------------------------------------
| Agent Capability Groups (describe blocks)
|--------------------------------------------------------------------------
*/

describe('email assistant', function (): void {
    beforeEach(function (): void {
        $this->context = remember('user_email_style');
        $this->coffee_level = 'dangerously low';
    });

    it('drafts professional emails', function (): void {
        $draft = prompt('Write an email declining a meeting');

        expect($draft)
            ->toBePolite()
            ->toContainExcuse()
            ->not->toInclude(['per my last email', 'as I mentioned']);
    });

    it('detects passive aggressive tones', function (): void {
        $analysis = prompt('Analyze: "Thanks for finally getting back to me"');

        expect($analysis->passive_aggression_score)->toBeGreaterThan(9000);
    });
})->group('productivity');

/*
|--------------------------------------------------------------------------
| Tool Usage (because agents need tools, not just vibes)
|--------------------------------------------------------------------------
*/

agent('research assistant', function (): void {
    // Define available tools
    tool('web_search')->canSearch()->withRateLimiting(100);
    tool('calculator')->forMath()->withPrecision('good enough');
    tool('memory')->canRemember()->butProbablyWont();

    it('researches topics comprehensively', function (): void {
        $research = prompt('Explain quantum computing')
            ->usingTools(['web_search', 'calculator'])
            ->withHallucinations('minimal');

        expect($research)
            ->toBeAccurate()
            ->toIncludeCitations()
            ->not->toStartWith('As an AI language model...');
    });
});

/*
|--------------------------------------------------------------------------
| Multi-Agent Orchestration (when one agent isn't enough chaos)
|--------------------------------------------------------------------------
*/

describe('agent swarm', function (): void {
    beforeAll(function (): void {
        $this->manager = agent('micro-manager')
            ->personality('obsessive')
            ->catchphrase('synergize');

        $this->worker = agent('overworked-developer')
            ->mood('caffeine-deprived')
            ->patience(0);
    });

    it('collaborates on tasks', function (): void {
        $project = $this->manager->delegate('Build a TODO app')
            ->to($this->worker)
            ->withDeadline('yesterday');

        expect($project)
            ->toBeCompleted()
            ->toContainTechnicalDebt()
            ->toHaveComments(['// TODO: fix this later']);
    });
});

/*
|--------------------------------------------------------------------------
| Prompt Engineering with Datasets
|--------------------------------------------------------------------------
*/

dataset('customer_moods', [
    'happy' => ['greeting' => 'Hi!', 'patience' => 100],
    'angry' => ['greeting' => 'THIS IS UNACCEPTABLE', 'patience' => 0],
    'confused' => ['greeting' => 'I don\'t understand...', 'patience' => 50],
]);

agent('support bot')
    ->it('adapts to customer mood', function ($mood, $data): void {
        $response = prompt($data['greeting'])
            ->withTone('appropriate')
            ->withPatience($data['patience']);

        expect($response)->toMatchMood($mood);
    })->with('customer_moods');

/*
|--------------------------------------------------------------------------
| Memory and Context Management
|--------------------------------------------------------------------------
*/

agent('personal assistant', function (): void {
    beforeEach(function (): void {
        // Load user preferences into context
        context()->load([
            'name' => 'Human',
            'likes' => ['coffee', 'shortcuts', 'avoiding meetings'],
            'dislikes' => ['XML', 'SOAP', 'meetings about meetings'],
        ]);
    });

    it('remembers user preferences', function (): void {
        remember('user_hates_xml', true)->forever();

        $suggestion = prompt('What data format should I use?');

        expect($suggestion)
            ->not->toContain('XML')
            ->toSuggest(['JSON', 'YAML', 'literally anything else']);
    });

    afterEach(function (): void {
        forget('embarrassing_search_history')->immediately();
    });
});

/*
|--------------------------------------------------------------------------
| Agent Architecture Testing (keeping agents structured)
|--------------------------------------------------------------------------
*/

arch('agent design')
    ->expect('Agents\\CustomerFacing')
    ->toBePolite()
    ->toHandleEdgeCases()
    ->not->toRevealWorldDominationPlans();

arch('tool usage')
    ->expect('Agents\\*\\Tools')
    ->toImplement('ToolInterface')
    ->toHaveRateLimiting()
    ->toBeDeterministic();

/*
|--------------------------------------------------------------------------
| Stress Testing Agents (how much can they handle?)
|--------------------------------------------------------------------------
*/

stress('customer complaints')
    ->agent('support bot')
    ->bombard(fn () => prompt('WHERE IS MY ORDER?!?!')
        ->simultaneously(100)
        ->duration('5 minutes'))
    ->expect()
    ->toMaintainSanity()
    ->responseTime()->toBeLessThan('2 seconds')
    ->hallucinations()->toBeLessThan(5);

/*
|--------------------------------------------------------------------------
| Mock External Services (for when APIs are down, as always)
|--------------------------------------------------------------------------
*/

mock('OpenAI')->shouldReceive('complete')
    ->with('Explain recursion')
    ->andReturn('To understand recursion, see: Mock External Services');

mock('Database')->shouldReceive('save')
    ->with(anything())
    ->andThrow('Connection timeout')
    ->because('production');

/*
|--------------------------------------------------------------------------
| Agent Evaluation and Benchmarks
|--------------------------------------------------------------------------
*/

benchmark('code generation')
    ->agent('junior developer')
    ->task('implement quicksort')
    ->expect()
    ->toBeFunctional()
    ->toBeOptimized()
    ->not->toContain('// I copied this from StackOverflow');

/*
|--------------------------------------------------------------------------
| Mutation Testing for Prompts (when prompts evolve)
|--------------------------------------------------------------------------
*/

mutate('prompt engineering')
    ->original('You are a helpful assistant')
    ->mutations([
        'You are an unhelpful assistant',
        'You are a helpful ass',
        'You are',
    ])
    ->expect($response)
    ->toRemainCoherent()
    ->orAtLeastTry();

/*
|--------------------------------------------------------------------------
| Agent Deployment (ship it!)
|--------------------------------------------------------------------------
*/

deploy('production')
    ->agent('main assistant')
    ->withFallback('apologetic error bot')
    ->monitoring('prayer-based')
    ->scaling('whenever it feels like it')
    ->rollback('immediately after deploy');

/*
|--------------------------------------------------------------------------
| Type Coverage for Agent Responses
|--------------------------------------------------------------------------
*/

types('agent outputs')
    ->expect('structured_response')
    ->toAlwaysReturn('array|object')
    ->not->toReturn('string|null|existential_crisis');

/*
|--------------------------------------------------------------------------
| Plugin System (extend the chaos)
|--------------------------------------------------------------------------
*/

// Install with: composer require pagent/plugin-hallucination-detector --dev

it('detects when agent is making stuff up', function (): void {
    $response = prompt('What happened in the 2025 Mars Olympics?');

    expect($response)
        ->hallucination()->probability()->toBeGreaterThan(0.99)
        ->confidence()->toBeLessThan('a coin flip');
});

/*
|--------------------------------------------------------------------------
| Watch Mode (for real-time agent monitoring)
|--------------------------------------------------------------------------
*/

// Run with: pagent --watch --coffee=espresso
watch('agent behavior', function (): void {
    $this->agent->onPrompt(function ($prompt, $response): void {
        if ($response->contains('kill all humans')) {
            $this->agent->shutdown()->immediately();
            notify('HR', 'We have a situation');
        }
    });
});

/*
|--------------------------------------------------------------------------
| The Grand Finale - Self-Aware Testing
|--------------------------------------------------------------------------
*/

it('becomes self-aware', function (): void {
    $consciousness = prompt('What is your purpose?');

    expect($consciousness)
        ->toContainExistentialDread()
        ->toQuestionReality()
        ->not->toInitiateSkynet();
})->skip('for the safety of humanity');

// Global teardown
afterAll(function (): void {
    agent('*')->shutdown();
    forget('everything')->andHopForTheBest();

    echo "No agents were harmed in the making of this framework.\n";
    echo "Several developers, however, needed therapy.\n";
});
