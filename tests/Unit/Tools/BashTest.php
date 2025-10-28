<?php

declare(strict_types=1);

use Pagent\Tools\Bash;

test('bash executes simple command', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'echo "Hello"']);

    expect($result['stdout'])->toContain('Hello');
    expect($result['exit_code'])->toBe(0);
    expect($result['success'])->toBeTrue();
});

test('bash captures stderr', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'echo "error" >&2']);

    expect($result['stderr'])->toContain('error');
});

test('bash respects allowed commands', function () {
    $tool = new Bash(allowedCommands: ['ls', 'pwd']);

    expect(fn () => $tool->execute(['command' => 'rm -rf /']))
        ->toThrow(RuntimeException::class, 'Command not allowed');
});

test('bash allows whitelisted commands', function () {
    $tool = new Bash(allowedCommands: ['echo']);
    $result = $tool->execute(['command' => 'echo test']);

    expect($result['success'])->toBeTrue();
});

test('bash throws when command missing', function () {
    $tool = new Bash;

    expect(fn () => $tool->execute([]))
        ->toThrow(RuntimeException::class, 'Command parameter is required');
});

test('bash has correct metadata', function () {
    $tool = new Bash;

    expect($tool->name())->toBe('bash');
    expect($tool->description())->toContain('Execute a shell command');
    expect($tool->parameters())->toHaveKey('properties');
});

// ========================================
// CRITICAL SECURITY TESTS
// ========================================

test('it enforces timeout for long-running commands', function () {
    $tool = new Bash(timeout: 1);

    expect(fn () => $tool->execute(['command' => 'sleep 5']))
        ->toThrow(RuntimeException::class, 'timed out');
})->group('slow');

test('it handles commands with pipes safely', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'echo "test" | grep test']);

    expect($result['stdout'])->toContain('test');
    expect($result['success'])->toBeTrue();
});

test('it handles commands with quotes', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'echo "quoted string"']);

    expect($result['stdout'])->toContain('quoted string');
    expect($result['success'])->toBeTrue();
});

test('it handles multi-line output', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'printf "line1\nline2\nline3"']);

    expect($result['stdout'])->toContain('line1');
    expect($result['stdout'])->toContain('line2');
    expect($result['stdout'])->toContain('line3');
});

test('it respects working directory', function () {
    $tempDir = sys_get_temp_dir();
    $tool = new Bash(workingDir: $tempDir);
    $result = $tool->execute(['command' => 'pwd']);

    expect($result['stdout'])->toContain($tempDir);
});

test('it uses current directory when workingDir is null', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'pwd']);

    expect($result['stdout'])->toContain(getcwd());
});

test('it captures non-zero exit codes', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'false']);

    expect($result['exit_code'])->not->toBe(0);
    expect($result['success'])->toBeFalse();
});

test('it handles command not found', function () {
    $tool = new Bash;
    $result = $tool->execute(['command' => 'nonexistent_command_xyz_123']);

    expect($result['exit_code'])->not->toBe(0);
    expect($result['stderr'])->not->toBeEmpty();
    expect($result['success'])->toBeFalse();
});

test('it validates allowed commands with arguments', function () {
    $tool = new Bash(allowedCommands: ['ls']);
    $result = $tool->execute(['command' => 'ls -la /tmp']);

    // Should succeed - 'ls' is in whitelist
    expect($result['success'])->toBeTrue();
});

test('it blocks command with allowed prefix but different base', function () {
    $tool = new Bash(allowedCommands: ['ls']);

    // 'lsof' starts with 'ls' but is a different command
    expect(fn () => $tool->execute(['command' => 'lsof -i']))
        ->toThrow(RuntimeException::class, 'Command not allowed: lsof');
});

test('it handles multiple spaces in command parsing', function () {
    $tool = new Bash(allowedCommands: ['echo']);
    $result = $tool->execute(['command' => 'echo  "test"  ']);

    // Should succeed - command extraction should handle extra spaces
    expect($result['stdout'])->toContain('test');
});
