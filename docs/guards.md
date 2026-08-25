# Guards

Guards are phase-aware safety policies. Input guards run before a provider request
or a tool can execute; output guards run after the final model response and before
it is committed to conversation history or returned to the caller.

## Built-in policies

- `PromptInjectionGuard` is an `InputGuard`. It rejects suspicious user input
  before it leaves the agent.
- `PIIGuard` and `ContentFilterGuard` are `OutputGuard`s. They inspect generated
  content before it is exposed.

```php
$assistant = agent('public-assistant')
    ->provider('anthropic')
    ->guard('promptInjection') // before provider/tool execution
    ->guard('pii')             // before output is committed or returned
    ->guard('contentFilter');
```

The built-in PII policy detects SSNs, credit cards, email addresses, phone
numbers, and optionally IP addresses. `ContentFilterGuard` supports custom regex
patterns and strict mode.

```php
use Pagent\Guards\ContentFilterGuard;
use Pagent\Guards\PIIGuard;

$assistant
    ->guard(new PIIGuard(['ssn', 'credit_card', 'email']))
    ->guard(new ContentFilterGuard(
        customPatterns: ['/\\bsecret-word\\b/i'],
        strictMode: true,
    ));
```

## Custom input guards

Implement `InputGuard` for a policy that must prevent a request from reaching a
provider or tool. It still implements the legacy `Guard` methods for compatibility,
but Pagent calls `checkInput()` in the input phase.

```php
use Pagent\Contracts\InputGuard;

final class TenantBoundaryGuard implements InputGuard
{
    public function checkInput(string $input): bool
    {
        return !str_contains($input, 'other-tenant-secret');
    }

    public function check(string $input, string $output): bool
    {
        return $this->checkInput($input);
    }

    public function getName(): string
    {
        return 'tenant_boundary';
    }

    public function getViolationMessage(): string
    {
        return 'The request crosses a tenant boundary.';
    }
}
```

## Custom output guards

Implement `OutputGuard` for a policy over generated content. Return `false` from
`supportsIncrementalInspection()` unless it is safe to release every prefix of a
response. A value such as an email address can cross chunk boundaries, so a PII
policy must return `false`.

```php
use Pagent\Contracts\OutputGuard;

final class BrandNameGuard implements OutputGuard
{
    public function checkOutput(string $output): bool
    {
        return !str_contains(mb_strtolower($output), 'forbidden brand');
    }

    public function supportsIncrementalInspection(): bool
    {
        return false;
    }

    public function check(string $input, string $output): bool
    {
        return $this->checkOutput($output);
    }

    public function getName(): string
    {
        return 'brand_name';
    }

    public function getViolationMessage(): string
    {
        return 'Response contains a forbidden brand.';
    }
}
```

## Legacy guards and closures

The original `Guard::check(string $input, string $output)` contract and
`guard('name', fn ($input, $output) => ...)` remain supported. Their phase cannot
be inferred safely, so Pagent treats them as output policies. Prefer
`InputGuard`/`OutputGuard` for new code.

```php
$assistant->guard(
    'legacy_length_check',
    fn (string $input, string $output): bool => mb_strlen($output) < 1_000,
);
```

## Streaming safety

Streams are normally delivered incrementally. Pagent buffers and validates before
delivery if a stream has a non-incremental output guard, a legacy guard, or
middleware that can transform the response. This deliberately trades time to first
token for the guarantee that rejected content is never sent to the callback.

```php
$assistant
    ->guard(new PIIGuard())
    ->streamTo('Summarize this document', function ($chunk): void {
        if ($chunk->isText()) {
            echo $chunk->content;
        }
    });
```

## Violations and fallbacks

A rejected turn throws `GuardException` unless the agent has a fallback. Rejected
input is not retained in history; provider/tool failures and all other failed turns
roll back their staged conversation state.

```php
use Pagent\Exceptions\GuardException;

$assistant->fallback(
    fn (GuardException $error): string => 'This request cannot be processed.',
);

try {
    $response = $assistant->prompt($userInput);
} catch (GuardException $error) {
    logger()->warning('Guard blocked a turn', ['guard' => $error->guardName]);
}
```

## Events

Guards emit `GuardCheckingEvent`, `GuardPassedEvent`, `GuardViolatedEvent`, and
`GuardFallbackEvent`. Agent-local listeners and global `EventManager` subscribers
observe the same event publication.

See [events](events.md) for listener examples and [streaming](streaming.md) for
SSE delivery details.
