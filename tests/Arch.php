<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Tests
|--------------------------------------------------------------------------
|
| These tests ensure that the codebase follows architectural constraints
| and best practices. They run automatically with the test suite.
|
*/

arch('library files use strict types')
    ->expect('Pagent')
    ->toUseStrictTypes();

arch('library files do not use debugging functions')
    ->expect('Pagent')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit', 'ray']);

arch('contracts are interfaces')
    ->expect('Pagent\Contracts')
    ->toBeInterfaces();

arch('exceptions extend base exception')
    ->expect('Pagent\Exceptions')
    ->toExtend(Exception::class);

arch('providers implement provider contract')
    ->expect('Pagent\Providers')
    ->toImplement('Pagent\Contracts\Provider');

arch('guards implement guard contract')
    ->expect('Pagent\Guards')
    ->toImplement('Pagent\Contracts\Guard');

arch('middleware implements middleware contract')
    ->expect('Pagent\Middleware')
    ->toImplement('Pagent\Contracts\Middleware');

arch('tools extend base tool class')
    ->expect('Pagent\Tools')
    ->toExtend('Pagent\Tools\Tool')
    ->ignoring('Pagent\Tools\Tool');

arch('no usage of env helper in library code')
    ->expect('Pagent')
    ->not->toUse(['env', 'getenv'])
    ->ignoring('Pagent\Providers');

arch('no global state manipulation')
    ->expect('Pagent')
    ->not->toUse(['extract', 'compact', 'global'])
    ->ignoring('Pagent\Registry');

arch('contracts do not have dependencies on implementations')
    ->expect('Pagent\Contracts')
    ->not->toUse('Pagent\Providers')
    ->not->toUse('Pagent\Guards')
    ->not->toUse('Pagent\Middleware')
    ->not->toUse('Pagent\Tools');
