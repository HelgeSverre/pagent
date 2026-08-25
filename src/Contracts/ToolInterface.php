<?php

declare(strict_types=1);

namespace Pagent\Contracts;

/**
 * @deprecated Use {@see Tool}. This compatibility contract adds no methods;
 * applications that type-hinted it can migrate without adapting tool classes.
 *
 * Provider wire formats are deliberately not part of the tool contract. Use
 * the provider-boundary serializer instead.
 */
interface ToolInterface extends Tool {}
