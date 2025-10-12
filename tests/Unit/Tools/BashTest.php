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
