<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Tests\Services\Annotations;

use PHPUnit\Framework\TestCase;
use ServiceBus\Services\Annotations\EventListener;

/**
 *
 */
final class EventListenerTest extends TestCase
{
    /**
     * @test
     * @expectedException \InvalidArgumentException
     *
     * @return void
     */
    public function withWrongProperties(): void
    {
        new EventListener(['qwerty' => 'root']);
    }

    /**
     * @test
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function withoutAnyFields(): void
    {
        $annotation = new EventListener([]);

        static::assertFalse($annotation->validate);
        static::assertEmpty($annotation->groups);
    }

    /**
     * @test
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function withValidation(): void
    {
        $annotation = new EventListener([
            'validate' => true,
            'groups'   => [
                'qwerty',
                'root'
            ]
        ]);

        static::assertTrue($annotation->validate);
        static::assertEquals(['qwerty', 'root'], $annotation->groups);
    }

    /**
     * @test
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function withCustomEvents(): void
    {
        $handler = new EventListener([
                'defaultValidationFailedEvent' => EventListener::class,
                'defaultThrowableEvent'        => \Throwable::class
            ]
        );

        self::assertEquals(EventListener::class, $handler->defaultValidationFailedEvent);
        self::assertEquals(\Throwable::class, $handler->defaultThrowableEvent);
    }
}