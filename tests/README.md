# Pagent Test Suite

## Running Tests

### Basic Tests (No API calls)

```bash
# Run all tests except API tests
./vendor/bin/pest --exclude-group=api

# Run only unit tests
./vendor/bin/pest tests/Unit
```

### API Integration Tests

These tests make real API calls and require valid API keys.

#### Setup

```bash
# Set API keys as environment variables
export ANTHROPIC_API_KEY="your-anthropic-key"
export OPENAI_API_KEY="your-openai-key"
```

#### Running API Tests

```bash
# Run all API tests
./vendor/bin/pest --group=api

# Run only Anthropic tests
./vendor/bin/pest --group=anthropic

# Run only OpenAI tests
./vendor/bin/pest --group=openai

# Run a specific test file
./vendor/bin/pest tests/Integration/RealAPITest.php
```

## Test Structure

### Unit Tests

- `AgentTest.php` - Core Agent class functionality
- `AgentBuilderTest.php` - Builder pattern tests
- `RegistryTest.php` - Agent registry tests
- `FunctionsTest.php` - Global helper functions
- `Providers/` - Provider-specific unit tests (no API calls)

### Integration Tests

- `BasicUsageTest.php` - Mock provider integration examples
- `RealAPITest.php` - Real API calls with basic functionality
- `ProviderFeaturesTest.php` - Provider-specific features and advanced usage

## Writing New Tests

### Mock Tests

Use the `Mock` provider for tests that don't need real API calls:

```php
$mock = new Mock(['responses' => [
    'test prompt' => 'expected response'
]]);
```

### API Tests

Always check for API keys and skip if not available:

```php
beforeEach(function () {
    if (!getenv('ANTHROPIC_API_KEY')) {
        $this->markTestSkipped('ANTHROPIC_API_KEY not set');
    }
});
```

Use the `@group api` annotation for all API tests:

```php
/**
 * @group api
 * @group anthropic
 */
it('makes real API call', function () {
    // test code
});
```
