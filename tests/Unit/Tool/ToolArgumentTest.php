<?php

declare(strict_types=1);

use Pagent\Tool\ToolArgument;

test('it constructs with required parameters', function (): void {
    $arg = new ToolArgument('email', 'string');

    expect($arg->name)->toBe('email')
        ->and($arg->type)->toBe('string')
        ->and($arg->nullable)->toBeFalse()
        ->and($arg->description)->toBeNull()
        ->and($arg->default)->toBeNull();
});

test('it constructs with all optional parameters', function (): void {
    $arg = new ToolArgument(
        name: 'age',
        type: 'int',
        nullable: true,
        description: 'User age in years',
        default: 18
    );

    expect($arg->name)->toBe('age')
        ->and($arg->type)->toBe('int')
        ->and($arg->nullable)->toBeTrue()
        ->and($arg->description)->toBe('User age in years')
        ->and($arg->default)->toBe(18);
});

test('it converts int to integer in JSON schema', function (): void {
    $arg = new ToolArgument('count', 'int');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('integer');
});

test('it converts float to number in JSON schema', function (): void {
    $arg = new ToolArgument('price', 'float');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('number');
});

test('it converts bool to boolean in JSON schema', function (): void {
    $arg = new ToolArgument('active', 'bool');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('boolean');
});

test('it converts array to array in JSON schema', function (): void {
    $arg = new ToolArgument('items', 'array');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('array');
});

test('it converts object to object in JSON schema', function (): void {
    $arg = new ToolArgument('data', 'object');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('object');
});

test('it converts stdClass to object in JSON schema', function (): void {
    $arg = new ToolArgument('data', 'stdClass');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('object');
});

test('it defaults unknown types to string in JSON schema', function (): void {
    $arg = new ToolArgument('id', 'SomeCustomClass');
    $schema = $arg->toJsonSchema();

    expect($schema['type'])->toBe('string');
});

test('it includes description in JSON schema when provided', function (): void {
    $arg = new ToolArgument('name', 'string', description: 'User full name');
    $schema = $arg->toJsonSchema();

    expect($schema)->toHaveKey('description')
        ->and($schema['description'])->toBe('User full name');
});

test('it omits description from JSON schema when null', function (): void {
    $arg = new ToolArgument('name', 'string');
    $schema = $arg->toJsonSchema();

    expect($schema)->not->toHaveKey('description');
});

test('it handles nullable parameter correctly', function (): void {
    $nullableArg = new ToolArgument('optional_field', 'string', nullable: true);
    $requiredArg = new ToolArgument('required_field', 'string', nullable: false);

    expect($nullableArg->nullable)->toBeTrue()
        ->and($requiredArg->nullable)->toBeFalse();
});

test('it handles default values of different types', function (): void {
    $stringArg = new ToolArgument('name', 'string', default: 'default_name');
    $intArg = new ToolArgument('count', 'int', default: 0);
    $boolArg = new ToolArgument('active', 'bool', default: true);
    $arrayArg = new ToolArgument('tags', 'array', default: []);

    expect($stringArg->default)->toBe('default_name')
        ->and($intArg->default)->toBe(0)
        ->and($boolArg->default)->toBeTrue()
        ->and($arrayArg->default)->toBe([]);
});

test('it generates complete JSON schema with all properties', function (): void {
    $arg = new ToolArgument(
        name: 'score',
        type: 'float',
        nullable: false,
        description: 'User score between 0 and 100'
    );

    $schema = $arg->toJsonSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema)->toHaveKey('description')
        ->and($schema['type'])->toBe('number')
        ->and($schema['description'])->toBe('User score between 0 and 100');
});
