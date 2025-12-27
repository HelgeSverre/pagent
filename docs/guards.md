# Guards

Guards provide a safety layer that validates LLM responses before they're returned to users. They can detect and block responses containing sensitive information, inappropriate content, or signs of prompt injection attacks.

## How Guards Work

Guards are executed after the LLM responds but before the response is returned. Each guard implements a simple interface:

```php
interface Guard
{
    public function check(string $input, string $output): bool;
    public function getName(): string;
    public function getViolationMessage(): string;
}
```

- `check()` returns `true` if the response is safe, `false` if it should be blocked
- `$input` is the user's message
- `$output` is the LLM's response

## Built-in Guards

### PIIGuard

Detects personally identifiable information in responses:

```php
use Pagent\Guards\PIIGuard;

agent('assistant')
    ->provider('anthropic')
    ->guard(new PIIGuard())
    ->prompt('What is my SSN?');
```

**Default patterns detected:**
- Social Security Numbers (SSN): `123-45-6789`
- Credit card numbers: `1234-5678-9012-3456`
- Email addresses
- Phone numbers

**Customizing which checks are enabled:**

```php
// Only check for SSN and credit cards
$guard = new PIIGuard(['ssn', 'credit_card']);

// All available checks: 'ssn', 'credit_card', 'email', 'phone', 'ip_address'
```

### ContentFilterGuard

Blocks responses containing profanity, violent content, or security-related instructions:

```php
use Pagent\Guards\ContentFilterGuard;

agent('assistant')
    ->provider('anthropic')
    ->guard(new ContentFilterGuard())
    ->prompt('Tell me a story');
```

**Adding custom patterns:**

```php
$guard = new ContentFilterGuard(
    customPatterns: ['/\bsecret-word\b/i'],
    strictMode: true
);
```

### PromptInjectionGuard

Detects attempts to manipulate the LLM through prompt injection in user input:

```php
use Pagent\Guards\PromptInjectionGuard;

agent('assistant')
    ->provider('anthropic')
    ->guard(new PromptInjectionGuard())
    ->prompt($userInput);  // Validates user input for injection attempts
```

**Patterns detected:**
- "Ignore previous instructions"
- "Forget everything"
- "You are now..."
- "[SYSTEM]" markers
- "New instructions:"

## Using Guards

### By String Name

```php
agent('assistant')
    ->provider('anthropic')
    ->guard('pii')              // PIIGuard
    ->guard('contentFilter')    // ContentFilterGuard
    ->guard('promptInjection')  // PromptInjectionGuard
    ->prompt('Hello');
```

### By Instance

```php
use Pagent\Guards\PIIGuard;

agent('assistant')
    ->provider('anthropic')
    ->guard(new PIIGuard(['ssn', 'email']))
    ->prompt('Hello');
```

### Multiple Guards

Guards are executed in order. If any guard fails, the response is blocked:

```php
agent('assistant')
    ->provider('anthropic')
    ->guard('promptInjection')  // Check input first
    ->guard('pii')              // Then check output
    ->guard('contentFilter')    // Then filter content
    ->prompt($userInput);
```

## Creating Custom Guards

```php
use Pagent\Contracts\Guard;

final class MyCustomGuard implements Guard
{
    public function check(string $input, string $output): bool
    {
        // Return true if safe, false to block
        return !str_contains($output, 'forbidden-word');
    }

    public function getName(): string
    {
        return 'my_custom_guard';
    }

    public function getViolationMessage(): string
    {
        return 'Response contained forbidden content.';
    }
}

// Use it
agent('bot')->guard(new MyCustomGuard());
```

### Closure-based Guards

For simple checks, use a closure:

```php
agent('assistant')
    ->provider('anthropic')
    ->guard('length_check', fn($input, $output) => strlen($output) < 1000)
    ->prompt('Write a short story');
```

## Handling Guard Violations

When a guard fails, a `GuardException` is thrown by default:

```php
use Pagent\Exceptions\GuardException;

try {
    $response = agent('assistant')
        ->guard('pii')
        ->prompt('What is 123-45-6789?');
} catch (GuardException $e) {
    echo "Blocked: " . $e->getMessage();
    echo "Guard: " . $e->guardName;
}
```

### Using Fallbacks

Provide a fallback response instead of throwing:

```php
agent('assistant')
    ->provider('anthropic')
    ->guard('pii')
    ->fallback(fn() => "I can't share that information.")
    ->prompt('What is my SSN?');
```

## Guard Events

Guards emit events for observability:

| Event | When |
|-------|------|
| `GuardCheckingEvent` | Before guard executes |
| `GuardPassedEvent` | Guard check passed |
| `GuardViolatedEvent` | Guard check failed |
| `GuardFallbackEvent` | Fallback was used |

```php
use Pagent\Events\Events\Guard\GuardViolatedEvent;

agent('assistant')
    ->on(GuardViolatedEvent::class, function($event) {
        log_security_event($event->guardName, $event->input);
    })
    ->guard('pii')
    ->prompt($userInput);
```

## Best Practices

1. **Order matters**: Place `PromptInjectionGuard` first to validate input before processing
2. **Layer guards**: Use multiple guards for defense in depth
3. **Use fallbacks**: Provide graceful degradation instead of errors for user-facing apps
4. **Log violations**: Use events to track guard violations for security monitoring
5. **Test guards**: Include edge cases in your test suite

## See Also

- [Middleware](middleware.md) - For request/response transformation
- [Events](events.md) - For guard event handling
- [Observability](observability.md) - For telemetry and monitoring
