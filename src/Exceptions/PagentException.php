<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

use Throwable;

/**
 * Marker implemented by framework-defined exceptions, so callers can catch
 * library failures at one boundary. Exceptions thrown by application
 * callbacks or third-party dependencies may still propagate unchanged.
 */
interface PagentException extends Throwable {}
