<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Exceptions\PagentException;
use Pagent\ProviderFactory;
use Pagent\Tools\WebFetch;

test('framework-defined failures share one catch boundary', function (Closure $operation): void {
    try {
        $operation();
        test()->fail('Expected a framework exception');
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(PagentException::class);
    }
})->with([
    'agent lifecycle' => fn (): object => (new Agent('missing-provider'))->prompt('hello'),
    'provider configuration' => fn (): object => ProviderFactory::resolve('definitely-missing-provider'),
    'tool validation' => fn (): mixed => (new WebFetch)->execute(['url' => 'file:///etc/passwd']),
]);
