# Security Policy
gm
## Supported Versions

We actively support the following versions of Pagent with security updates:

| Version | Supported          |
| ------- | ------------------ |
| 0.5.x   | :white_check_mark: |
| 0.4.x   | :white_check_mark: |
| < 0.4   | :x:                |

## Reporting a Vulnerability

We take the security of Pagent seriously. If you discover a security vulnerability, please follow these steps:

### 1. Do Not Publicly Disclose

Please **do not** create a public GitHub issue for security vulnerabilities. Public disclosure can put users at risk.

### 2. Report Privately

Send your vulnerability report to: **helge.sverre@gmail.com**

Include the following information:
- Description of the vulnerability
- Steps to reproduce the issue
- Potential impact
- Suggested fix (if you have one)
- Your contact information

### 3. Response Timeline

- **Initial Response**: Within 48 hours
- **Status Update**: Within 7 days
- **Fix Timeline**: Varies based on severity (typically 7-30 days)

### 4. Disclosure Process

1. We will acknowledge receipt of your report
2. We will investigate and validate the vulnerability
3. We will develop and test a fix
4. We will release a security patch
5. We will credit you in the security advisory (unless you prefer to remain anonymous)

## Security Best Practices

When using Pagent, follow these security best practices:

### API Keys and Secrets

- **Never commit API keys** to version control
- Use environment variables: `$_ENV['ANTHROPIC_API_KEY']`, `getenv('OPENAI_API_KEY')`
- Use `.env` files with `vlucas/phpdotenv` for local development
- Rotate keys regularly

### Safety Guards

Always enable safety guards when handling user input:

```php
use Pagent\Guards\PIIDetectionGuard;
use Pagent\Guards\ContentFilterGuard;
use Pagent\Guards\PromptInjectionGuard;

agent('assistant')
    ->guard(new PIIDetectionGuard())
    ->guard(new ContentFilterGuard())
    ->guard(new PromptInjectionGuard());
```

### Tool Security

- **Validate all tool inputs** before executing external operations
- **Limit file system access** to specific directories
- **Sanitize paths** to prevent directory traversal attacks
- **Use timeouts** to prevent resource exhaustion
- **Validate return types** to ensure data integrity

Example:

```php
agent('assistant')
    ->tool('read_file', 'Read a file', function(string $path): string {
        // Validate path is within allowed directory
        $realPath = realpath($path);
        $baseDir = realpath('/allowed/directory');
        
        if (!str_starts_with($realPath, $baseDir)) {
            throw new SecurityException('Path traversal detected');
        }
        
        return file_get_contents($realPath);
    });
```

### Rate Limiting

Implement rate limiting for production applications:

```php
use Pagent\Middleware\RateLimitMiddleware;

agent('assistant')
    ->middleware(new RateLimitMiddleware(maxRequests: 100, perMinutes: 1));
```

### Content Filtering

Filter sensitive information from logs and responses:

```php
use Pagent\Middleware\LoggingMiddleware;

agent('assistant')
    ->middleware(new LoggingMiddleware(
        redactPatterns: ['/sk-[a-zA-Z0-9]+/', '/\b\d{16}\b/'] // API keys, credit cards
    ));
```

## Known Security Considerations

### 1. Tool Execution

Tools execute arbitrary PHP code. Only register tools from trusted sources and validate all inputs.

### 2. Prompt Injection

LLMs can be manipulated through carefully crafted prompts. Use `PromptInjectionGuard` to detect common patterns.

### 3. Data Privacy

LLM providers process your data. Review provider privacy policies and avoid sending sensitive information without proper safeguards.

### 4. Token Limits

Large conversations can exceed token limits. Implement conversation pruning to prevent denial-of-service.

## Security Updates

Security updates will be released as patch versions (e.g., 0.5.1) and announced via:
- GitHub Security Advisories
- Release notes
- CHANGELOG.md

Subscribe to repository notifications to stay informed.

## Bug Bounty

We do not currently offer a formal bug bounty program, but we deeply appreciate security researchers who report vulnerabilities responsibly. We will acknowledge your contribution in our security advisories.

## Contact

For security concerns: **helge.sverre@gmail.com**  
For general questions: **helge.sverre@gmail.com**

---

Thank you for helping keep Pagent and our community safe!
