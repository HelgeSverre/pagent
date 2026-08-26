<?php

declare(strict_types=1);

namespace Pagent\Mcp\Exceptions;

use Exception;
use Pagent\Exceptions\PagentException;

/**
 * Base exception for all MCP-related errors.
 */
class McpException extends Exception implements PagentException
{
    //
}
