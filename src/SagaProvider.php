<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 * Supports Saga pattern and Event Sourcing
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus;

use function Amp\call;
use Amp\Coroutine;
use Amp\Promise;
use Desperado\ServiceBus\Common\Contract\Messages\Command;
use Desperado\ServiceBus\Common\Contract\Messages\Message;
use function Desperado\ServiceBus\Common\datetimeInstantiator;
use Desperado\ServiceBus\Common\ExecutionContext\MessageDeliveryContext;
use function Desperado\ServiceBus\Common\invokeReflectionMethod;
use function Desperado\ServiceBus\Common\readReflectionPropertyValue;
use Desperado\ServiceBus\Infrastructure\Storage\Exceptions\ConnectionFailed;
use Desperado\ServiceBus\Infrastructure\Storage\Exceptions\StorageInteractingFailed;
use Desperado\ServiceBus\Sagas\Configuration\SagaMetadata;
use Desperado\ServiceBus\Sagas\Exceptions\DuplicateSagaId;
use Desperado\ServiceBus\Sagas\Exceptions\ExpiredSagaLoaded;
use Desperado\ServiceBus\Sagas\Exceptions\LoadSagaFailed;
use Desperado\ServiceBus\Sagas\Exceptions\SagaMetaDataNotFound;
use Desperado\ServiceBus\Sagas\Exceptions\SaveSagaFailed;
use Desperado\ServiceBus\Sagas\Exceptions\StartSagaFailed;
use Desperado\ServiceBus\Sagas\Saga;
use Desperado\ServiceBus\Sagas\SagaId;
use Desperado\ServiceBus\Sagas\SagaStore\SagasStore;
use Desperado\ServiceBus\Sagas\SagaStore\StoredSaga;
use Desperado\ServiceBus\Infrastructure\Storage\Exceptions\UniqueConstraintViolationCheckFailed;
use Kelunik\Retry\ConstantBackoff;
use function Kelunik\Retry\retry;

/**
 * Saga provider
 */
final class SagaProvider
{
    private const SAVE_SAGA_RETRY_COUNT = 5;
    private const SAVE_SAGA_RETRY_DELAY = 2000;

    /**
     * Sagas store
     *
     * @var SagasStore
     */
    private $store;

    /**
     * Sagas meta data
     *
     * @var array<string, \Desperado\ServiceBus\Sagas\Configuration\SagaMetadata>
     */
    private $sagaMetaDataCollection = [];

    /**
     * @param SagasStore $store
     */
    public function __construct(SagasStore $store)
    {
        $this->store = $store;
    }

    /**
     * Start new saga
     *
     * @param SagaId                 $id
     * @param Command                $command
     * @param MessageDeliveryContext $context
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Desperado\ServiceBus\Sagas\Saga>
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\StartSagaFailed
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\DuplicateSagaId
     */
    public function start(SagaId $id, Command $command, MessageDeliveryContext $context): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(SagaId $id, Command $command, MessageDeliveryContext $context): \Generator
            {
                try
                {
                    $sagaClass = $id->sagaClass();

                    $sagaMetaData = $this->extractSagaMetaData($sagaClass);

                    /** @var \DateTimeImmutable $expireDate */
                    $expireDate = datetimeInstantiator($sagaMetaData->expireDateModifier());

                    /** @var Saga $saga */
                    $saga = new $sagaClass($id, $expireDate);
                    $saga->start($command);

                    yield self::doStore($this->store, $saga, $context, true);

                    unset($sagaClass, $sagaMetaData, $expireDate);

                    return $saga;
                }
                catch(UniqueConstraintViolationCheckFailed $exception)
                {
                    throw new DuplicateSagaId($id);
                }
                catch(\Throwable $throwable)
                {
                    throw new StartSagaFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
                }
            },
            $id, $command, $context
        );
    }

    /**
     * Load saga
     *
     * @param SagaId                 $id
     * @param MessageDeliveryContext $context
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Desperado\ServiceBus\Sagas\Saga|null>
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\LoadSagaFailed
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ExpiredSagaLoaded
     */
    public function obtain(SagaId $id, MessageDeliveryContext $context): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(SagaId $id) use ($context): \Generator
            {
                try
                {
                    /** @var \DateTimeImmutable $currentDatetime */
                    $currentDatetime = datetimeInstantiator('NOW');

                    /** @var Saga|null $saga */
                    $saga = yield self::doLoad($this->store, $id);

                    if(null === $saga)
                    {
                        return null;
                    }

                    /** Non-expired saga */
                    if($saga->expireDate() > $currentDatetime)
                    {
                        unset($currentDatetime);

                        return $saga;
                    }

                    yield new Coroutine($this->doCloseExpired($saga, $context));

                    unset($saga);

                    throw new ExpiredSagaLoaded($id);
                }
                catch(ExpiredSagaLoaded $exception)
                {
                    throw $exception;
                }
                catch(\Throwable $throwable)
                {
                    throw new LoadSagaFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
                }
            },
            $id
        );
    }

    /**
     * Save saga
     *
     * @param Saga                   $saga
     * @param MessageDeliveryContext $context
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     *
     * @return Promise It does not return any result
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\SaveSagaFailed
     */
    public function save(Saga $saga, MessageDeliveryContext $context): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(Saga $saga, MessageDeliveryContext $context): \Generator
            {
                try
                {
                    /** @var Saga|null $existsSaga */
                    $existsSaga = yield self::doLoad($this->store, $saga->id());

                    if(null !== $existsSaga)
                    {
                        yield self::doStore($this->store, $saga, $context, false);

                        unset($existsSaga);

                        return;
                    }

                    throw new \RuntimeException(
                        \sprintf(
                            'Saga with identifier "%s:%s" not exists. Please, use start() method for saga creation',
                            (string) $saga->id(),
                            \get_class($saga->id())
                        )
                    );
                }
                catch(\Throwable $throwable)
                {
                    throw new SaveSagaFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
                }
            },
            $saga,
            $context
        );
    }

    /**
     * @psalm-suppress MixedTypeCoercion
     *
     * @param SagasStore $store
     * @param SagaId     $id
     *
     * @return Promise<\Desperado\ServiceBus\Sagas\Saga|null>
     */
    private static function doLoad(SagasStore $store, SagaId $id): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(SagaId $id) use ($store): \Generator
            {
                $saga = null;

                /** @var StoredSaga|null $savedSaga */
                $savedSaga = yield $store->load($id);

                if(null !== $savedSaga)
                {
                    /** @var Saga $saga */
                    $saga = \unserialize(\base64_decode($savedSaga->payload()), ['allowed_classes' => true]);

                    unset($savedSaga);
                }

                return $saga;
            },
            $id
        );
    }

    /**
     * Execute add/update saga entry
     *
     * @param SagasStore             $store
     * @param Saga                   $saga
     * @param MessageDeliveryContext $context
     * @param bool                   $isNew
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     *
     * @return Promise It does not return any result
     *
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\UniqueConstraintViolationCheckFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\ConnectionFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\StorageInteractingFailed
     */
    private function doStore(SagasStore $store, Saga $saga, MessageDeliveryContext $context, bool $isNew): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(Saga $saga, MessageDeliveryContext $context, bool $isNew) use ($store): \Generator
            {
                /** @var array<int, \Desperado\ServiceBus\Common\Contract\Messages\Command> $commands */
                $commands = invokeReflectionMethod($saga, 'firedCommands');

                /** @var array<int, \Desperado\ServiceBus\Common\Contract\Messages\Event> $events */
                $events = invokeReflectionMethod($saga, 'raisedEvents');

                /** @var \DateTimeImmutable|null $closedAt */
                $closedAt = readReflectionPropertyValue($saga, 'closedAt');

                /** @var \Desperado\ServiceBus\Sagas\SagaStatus $state */
                $state = readReflectionPropertyValue($saga, 'status');

                $savedSaga = StoredSaga::create(
                    $saga->id(), $state, \base64_encode(\serialize($saga)), $saga->createdAt(),
                    $saga->expireDate(), $closedAt
                );

                yield retry(
                    self::SAVE_SAGA_RETRY_COUNT,
                    static function() use ($store, $savedSaga, $isNew): \Generator
                    {
                        /** @var Promise $promise */
                        $promise = true === $isNew
                            ? $store->save($savedSaga)
                            : $store->update($savedSaga);

                        yield $promise;
                    },
                    [ConnectionFailed::class, StorageInteractingFailed::class],
                    new ConstantBackoff(self::SAVE_SAGA_RETRY_DELAY)
                );

                /** @var array<mixed, \Desperado\ServiceBus\Common\Contract\Messages\Message> $messages */
                $messages = \array_merge($commands, $events);

                /** @var Message $message */
                foreach($messages as $message)
                {
                    yield $context->delivery($message);
                }

                unset($commands, $events, $messages, $closedAt, $state, $savedSaga);
            },
            $saga,
            $context,
            $isNew
        );
    }

    /**
     * Close expired saga
     *
     * @param Saga                   $saga
     * @param MessageDeliveryContext $context
     *
     * @return \Generator It does not return any result
     *
     * @throws \ReflectionException
     * @throws \Throwable
     */
    private function doCloseExpired(Saga $saga, MessageDeliveryContext $context): \Generator
    {
        /** @var \Desperado\ServiceBus\Sagas\SagaStatus $currentStatus */
        $currentStatus = readReflectionPropertyValue($saga, 'status');

        if(true === $currentStatus->inProgress())
        {
            unset($currentStatus);

            invokeReflectionMethod($saga, 'makeExpired');

            yield $this->save($saga, $context);
        }
    }

    /**
     * Receive saga meta data information
     *
     * @param string $sagaClass
     *
     * @return SagaMetadata
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\SagaMetaDataNotFound
     */
    private function extractSagaMetaData(string $sagaClass): SagaMetadata
    {
        if(true === isset($this->sagaMetaDataCollection[$sagaClass]))
        {
            return $this->sagaMetaDataCollection[$sagaClass];
        }

        throw new SagaMetaDataNotFound($sagaClass);
    }

    /**
     * Add meta data for specified saga
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @see          MessageRoutesConfigurator::configure()
     *
     * @param string       $sagaClass
     * @param SagaMetadata $metadata
     *
     * @return void
     */
    private function appendMetaData(string $sagaClass, SagaMetadata $metadata): void
    {
        $this->sagaMetaDataCollection[$sagaClass] = $metadata;
    }
}
