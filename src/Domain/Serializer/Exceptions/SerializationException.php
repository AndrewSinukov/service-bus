<?php

/**
 * Command Query Responsibility Segregation, Event Sourcing implementation
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @url     https://github.com/mmasiukevich
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ConcurrencyFramework\Domain\Serializer\Exceptions;

use Desperado\ConcurrencyFramework\Domain\AbstractConcurrencyFrameworkException;

/**
 *
 */
class SerializationException extends AbstractConcurrencyFrameworkException
{

}