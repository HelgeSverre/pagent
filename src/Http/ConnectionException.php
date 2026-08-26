<?php

declare(strict_types=1);

namespace Pagent\Http;

use Pagent\Exceptions\PagentException;
use Pagent\Exceptions\RuntimeException;

final class ConnectionException extends RuntimeException implements PagentException {}
