<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

/** Base for framework-defined invalid arguments and options. */
class InvalidArgumentException extends \InvalidArgumentException implements PagentException {}
