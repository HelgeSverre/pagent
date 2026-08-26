<?php

declare(strict_types=1);

use Pagent\Contracts\Provider;
use Pagent\Tools\Bash;
use Pagent\Tools\DataExtract;
use Pagent\Tools\FileRead;
use Pagent\Tools\FileWrite;
use Pagent\Tools\Glob;
use Pagent\Tools\Grep;
use Pagent\Tools\PdfReader;
use Pagent\Tools\SearchTool;
use Pagent\Tools\WebFetch;

test('built-in tools reject invalid input types before performing work', function (): void {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) ['content' => '{}'];
        }
    };

    $search = new SearchTool(
        documents: [['id' => 1, 'content' => 'test']],
        storage: ':memory:',
    );

    $cases = [
        'bash command' => [new Bash(unrestricted: true), ['command' => ['echo test']], 'command parameter must be a string'],
        'data extract text' => [new DataExtract($provider), ['text' => [], 'schema' => []], 'text parameter must be a string'],
        'file read path' => [new FileRead, ['path' => 42], 'path parameter must be a string'],
        'file write content' => [new FileWrite, ['path' => 'file.txt', 'content' => true], 'content parameter must be a string'],
        'glob pattern' => [new Glob, ['pattern' => []], 'pattern parameter must be a string'],
        'grep regex flag' => [new Grep, ['pattern' => 'hello', 'files' => '*.php', 'regex' => 'false'], 'regex parameter must be a boolean'],
        'PDF path' => [new PdfReader, ['path' => false], 'path parameter must be a string'],
        'search limit' => [$search, ['query' => 'test', 'limit' => '10'], 'limit parameter must be an integer'],
        'web fetch URL' => [new WebFetch, ['url' => ['https://example.com']], 'url parameter must be a string'],
        'web fetch headers' => [new WebFetch, ['url' => 'https://example.com', 'headers' => 'Accept: text/plain'], 'headers parameter must be an object'],
    ];

    foreach ($cases as [$tool, $arguments, $message]) {
        expect(fn () => $tool->execute($arguments))
            ->toThrow(InvalidArgumentException::class, $message);
    }
});

test('data extraction requires an object JSON schema with object properties', function (): void {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) ['content' => '{}'];
        }
    };

    $tool = new DataExtract($provider);

    expect(fn () => $tool->execute([
        'text' => 'Test',
        'schema' => ['type' => [], 'properties' => 'not-an-object'],
    ]))->toThrow(RuntimeException::class, 'Schema must have "type" and "properties" fields');
});
