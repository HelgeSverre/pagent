<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

/** Base for framework-defined operational failures. */
class RuntimeException extends \RuntimeException implements PagentException {}
