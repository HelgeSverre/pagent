<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

/** Base for invalid framework lifecycle or protocol states. */
class LogicException extends \LogicException implements PagentException {}
