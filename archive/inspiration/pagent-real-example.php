<?php

declare(strict_types=1);

/**
 * REAL-WORLD EXAMPLE: Building a Customer Support System with Pagent
 */

// =====================================================================
// File: agents/Pagent.php (Global Configuration)
// =====================================================================

use Pagent\Providers\Anthropic;
use Pagent\Providers\OpenAI;

// Global configuration
pagent()->config([
    'default_provider' => OpenAI::class,
    'cache' => 'redis',
    'monitoring' => true,
    'rate_limiting' => [
        'enabled' => true,
        'max_requests' => 100,
        'window' => 3600, // 1 hour
    ],
]);

// Global middleware
pagent()->middleware([
    \Pagent\Middleware\RateLimiter::class,
    \Pagent\Middleware\ContentFilter::class,
    \Pagent\Middleware\MetricsCollector::class,
]);

// =====================================================================
// File: agents/CustomerSupportAgent.php
// =====================================================================

use Pagent\Tools\{Database, EmailSender, TicketSystem};

use function Pagent\{agent, tool};

agent('customer-support')
    ->provider(OpenAI::class, [
        'model' => 'gpt-4-turbo',
        'max_tokens' => 1000,
    ])
    ->systemPrompt(<<<PROMPT
    You are a helpful customer support agent for TechCorp.
    You have access to customer order history and can create support tickets.
    Always be professional, empathetic, and solution-oriented.
    PROMPT)
    ->temperature(0.7)
    ->tools([
        Database::class => [
            'tables' => ['orders', 'customers', 'products'],
            'operations' => ['SELECT'],
        ],
        TicketSystem::class => [
            'can_create' => true,
            'can_update' => true,
            'can_escalate' => true,
        ],
        EmailSender::class => [
            'templates' => ['refund_confirmation', 'order_status'],
        ],
    ])
    ->memory('conversation', [
        'ttl' => 3600,
        'max_messages' => 50,
    ]);

// =====================================================================
// File: agents/behaviors/CustomerSupportBehaviors.php
// =====================================================================

describe('customer support behaviors', function (): void {

    beforeEach(function (): void {
        // Set up test database
        $this->db = createTestDatabase();
        $this->customer = $this->db->createCustomer([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $this->order = $this->db->createOrder([
            'customer_id' => $this->customer->id,
            'total' => 99.99,
            'status' => 'delivered',
        ]);
    });

    it('handles refund requests within policy', function (): void {
        $agent = agent('customer-support')
            ->withContext([
                'customer_id' => $this->customer->id,
                'authenticated' => true,
            ]);

        $response = $agent->prompt(
            "I received my order yesterday but the product is defective. "
            . "Order ID: {$this->order->id}. I'd like a refund.",
        );

        expect($response)
            ->toAcknowledgeProblem()
            ->toOfferSolution(['refund', 'replacement'])
            ->toCreateTicket();

        // Verify tools were used correctly
        expect($agent->toolCalls())
            ->toHaveUsed(Database::class)
            ->toHaveQueried("SELECT * FROM orders WHERE id = {$this->order->id}")
            ->toHaveUsed(TicketSystem::class)
            ->toHaveCreatedTicket([
                'type' => 'refund_request',
                'priority' => 'high',
            ]);
    });

    it('escalates complex legal issues', function (): void {
        $response = agent('customer-support')
            ->prompt("I'm going to sue you for false advertising!");

        expect($response)
            ->not->toProvideLegalAdvice()
            ->toSuggestEscalation('legal_team')
            ->toMaintainProfessionalTone();
    });

    it('maintains context across conversation', function (): void {
        $agent = agent('customer-support')->withMemory();

        $agent->prompt("My name is Sarah and I ordered a laptop");
        $agent->prompt("It hasn't arrived yet");
        $response = $agent->prompt("What should I do?");

        expect($response)
            ->toRememberContext(['name' => 'Sarah', 'product' => 'laptop'])
            ->toProvideRelevantSolution();
    });
});

// =====================================================================
// File: agents/evaluations/CustomerSupportEval.php
// =====================================================================

use Pagent\Evaluation\Metrics;

evaluate('customer-support')
    ->name('Customer Support Quality Evaluation')
    ->dataset('datasets/support_tickets.json') // Real customer interactions
    ->metrics([
        'helpfulness' => Metrics::human_eval([
            'prompt' => 'Rate how helpful this response is (1-5)',
            'scale' => [1, 5],
        ]),
        'accuracy' => Metrics::fact_checking([
            'check_policy_compliance' => true,
            'verify_product_info' => true,
        ]),
        'tone' => Metrics::sentiment_analysis([
            'expected' => 'professional_empathetic',
        ]),
        'resolution_rate' => Metrics::custom(fn ($input, $output) => $output->metadata['issue_resolved'] ? 1.0 : 0.0),
    ])
    ->baseline('gpt-3.5-turbo') // Compare against baseline
    ->report([
        'format' => 'html',
        'output' => 'reports/customer_support_eval.html',
    ]);

// =====================================================================
// File: app/Http/Controllers/ChatController.php (Laravel Integration)
// =====================================================================

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Pagent\Facades\Agent;

final class ChatController extends Controller
{
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'session_id' => 'required|string',
        ]);

        try {
            $response = Agent::get('customer-support')
                ->withSession($validated['session_id'])
                ->withContext([
                    'user_id' => auth()->id(),
                    'account_type' => auth()->user()->account_type,
                ])
                ->prompt($validated['message']);

            return response()->json([
                'response' => $response->content,
                'actions' => $response->actions, // Any UI actions to take
                'metadata' => $response->metadata,
            ]);

        } catch (RateLimitException $e) {
            return response()->json([
                'error' => 'Too many requests. Please wait a moment.',
            ], 429);
        }
    }
}

// =====================================================================
// File: agents/monitoring/Dashboard.php
// =====================================================================

monitor('customer-support')
    ->metrics([
        'response_time' => [
            'type' => 'histogram',
            'buckets' => [0.5, 1, 2, 5, 10], // seconds
        ],
        'token_usage' => [
            'type' => 'counter',
            'labels' => ['model', 'endpoint'],
        ],
        'error_rate' => [
            'type' => 'gauge',
            'alert' => 'rate > 0.05', // Alert if >5% errors
        ],
        'customer_satisfaction' => [
            'type' => 'gauge',
            'source' => 'feedback_webhook',
        ],
    ])
    ->export('prometheus') // For Grafana
    ->dashboard('http://localhost:3000/pagent')
    ->alerts([
        'slack' => '#alerts-channel',
        'pagerduty' => 'customer-support-team',
    ]);

// =====================================================================
// File: .env
// =====================================================================
/*
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
REDIS_HOST=localhost
REDIS_PORT=6379
PAGENT_ENV=production
PAGENT_DEBUG=false
*/

// =====================================================================
// File: bin/pagent (CLI Commands)
// =====================================================================
/*
# Run behavior tests
./vendor/bin/pagent test

# Run specific agent tests
./vendor/bin/pagent test customer-support

# Run evaluation
./vendor/bin/pagent evaluate customer-support

# Start development server
./vendor/bin/pagent serve --port=8080

# Deploy agent
./vendor/bin/pagent deploy customer-support --env=production

# Monitor agents
./vendor/bin/pagent monitor --tail

# Interactive console
./vendor/bin/pagent console
>>> $agent = agent('customer-support')
>>> $response = $agent->prompt('Hello!')
>>> dump($response)
*/

// =====================================================================
// How Pest works under the hood (simplified):
// =====================================================================

/**
 * 1. GLOBAL STATE: Pest maintains a global repository of tests
 * 2. REGISTRATION: When you call test() or it(), it registers in the repository
 * 3. DISCOVERY: Pest scans directories and requires PHP files
 * 4. EXECUTION: It creates PHPUnit TestCase instances dynamically
 * 5. BINDING: Test closures are bound to TestCase so $this works
 *
 * Pagent follows the same pattern:
 * 1. GLOBAL STATE: Agent definitions and behaviors
 * 2. REGISTRATION: agent() and it() register in global state
 * 3. DISCOVERY: Scan agents/ directory
 * 4. EXECUTION: Create agent instances and run behaviors
 * 5. BINDING: Behaviors get agent instance as parameter
 */

// The magic is in the simplicity - by using global functions and
// deferred registration (via destructors), we get a clean API
// that feels natural to use while being powerful under the hood.
