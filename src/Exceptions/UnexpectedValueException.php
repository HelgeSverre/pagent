<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

/** Base for malformed values received at framework boundaries. */
class UnexpectedValueException extends \UnexpectedValueException implements PagentException {}
