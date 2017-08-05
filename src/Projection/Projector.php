<?php
/**
 * This file is part of the prooph/arangodb-event-store.
 * (c) 2017 prooph software GmbH <contact@prooph.de>
 * (c) 2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\ArangoDb\Projection;

use ArangoDBClient\Connection;
use ArangoDBClient\Cursor;
use ArangoDBClient\Statement;
use ArrayIterator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use EmptyIterator;
use Iterator;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\ArangoDb\EventStore as ArangoDbEventStore;
use Prooph\EventStore\ArangoDb\Exception\ProjectionAlreadyExistsException;
use Prooph\EventStore\ArangoDb\Exception\ProjectionNotCreatedException;
use Prooph\EventStore\ArangoDb\Exception\ProjectionNotFound;
use Prooph\EventStore\ArangoDb\Exception\RuntimeException;
use Prooph\EventStore\ArangoDb\Type\DeleteDocument;
use Prooph\EventStore\ArangoDb\Type\InsertDocument;
use Prooph\EventStore\ArangoDb\Type\ReadDocument;
use Prooph\EventStore\ArangoDb\Type\UpdateDocument;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Exception;
use Prooph\EventStore\Projection\ProjectionStatus;
use Prooph\EventStore\Projection\Projector as ProophProjector;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Prooph\EventStore\Util\ArrayCache;
use function Prooph\EventStore\ArangoDb\Fn\execute;

final class Projector implements ProophProjector
{
    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $eventStreamsTable;

    /**
     * @var string
     */
    private $projectionsTable;

    /**
     * @var array
     */
    private $streamPositions = [];

    /**
     * @var ArrayCache
     */
    private $cachedStreamNames;

    /**
     * @var int
     */
    private $persistBlockSize;

    /**
     * @var array
     */
    private $state = [];

    /**
     * @var ProjectionStatus
     */
    private $status;

    /**
     * @var callable|null
     */
    private $initCallback;

    /**
     * @var Closure|null
     */
    private $handler;

    /**
     * @var array
     */
    private $handlers = [];

    /**
     * @var boolean
     */
    private $isStopped = false;

    /**
     * @var ?string
     */
    private $currentStreamName = null;

    /**
     * @var int lock timeout in milliseconds
     */
    private $lockTimeoutMs;

    /**
     * @var int
     */
    private $eventCounter = 0;

    /**
     * @var int
     */
    private $sleep;

    /**
     * @var bool
     */
    private $triggerPcntlSignalDispatch;

    /**
     * @var array|null
     */
    private $query;

    /**
     * @var bool
     */
    private $streamCreated = false;

    public function __construct(
        EventStore $eventStore,
        Connection $connection,
        string $name,
        string $eventStreamsTable,
        string $projectionsTable,
        int $lockTimeoutMs,
        int $cacheSize,
        int $persistBlockSize,
        int $sleep,
        bool $triggerPcntlSignalDispatch = false
    ) {
        if ($triggerPcntlSignalDispatch && ! extension_loaded('pcntl')) {
            throw Exception\ExtensionNotLoadedException::withName('pcntl');
        }

        $this->eventStore = $eventStore;
        $this->connection = $connection;
        $this->name = $name;
        $this->eventStreamsTable = $eventStreamsTable;
        $this->projectionsTable = $projectionsTable;
        $this->lockTimeoutMs = $lockTimeoutMs;
        $this->cachedStreamNames = new ArrayCache($cacheSize);
        $this->persistBlockSize = $persistBlockSize;
        $this->sleep = $sleep;
        $this->status = ProjectionStatus::IDLE();
        $this->triggerPcntlSignalDispatch = $triggerPcntlSignalDispatch;

        while ($eventStore instanceof EventStoreDecorator) {
            $eventStore = $eventStore->getInnerEventStore();
        }

        if (! $eventStore instanceof ArangoDbEventStore) {
            throw new Exception\InvalidArgumentException('Unknown event store instance given');
        }
    }

    public function init(Closure $callback): ProophProjector
    {
        if (null !== $this->initCallback) {
            throw new RuntimeException('Projection already initialized');
        }

        $callback = Closure::bind($callback, $this->createHandlerContext($this->currentStreamName));

        $result = $callback();

        if (is_array($result)) {
            $this->state = $result;
        }

        $this->initCallback = $callback;

        return $this;
    }

    public function fromStream(string $streamName): ProophProjector
    {
        if (null !== $this->query) {
            throw new RuntimeException('From was already called');
        }

        $this->query['streams'][] = $streamName;

        return $this;
    }

    public function fromStreams(string ...$streamNames): ProophProjector
    {
        if (null !== $this->query) {
            throw new RuntimeException('From was already called');
        }

        foreach ($streamNames as $streamName) {
            $this->query['streams'][] = $streamName;
        }

        return $this;
    }

    public function fromCategory(string $name): ProophProjector
    {
        if (null !== $this->query) {
            throw new RuntimeException('From was already called');
        }

        $this->query['categories'][] = $name;

        return $this;
    }

    public function fromCategories(string ...$names): ProophProjector
    {
        if (null !== $this->query) {
            throw new RuntimeException('From was already called');
        }

        foreach ($names as $name) {
            $this->query['categories'][] = $name;
        }

        return $this;
    }

    public function fromAll(): ProophProjector
    {
        if (null !== $this->query) {
            throw new RuntimeException('From was already called');
        }

        $this->query['all'] = true;

        return $this;
    }

    public function when(array $handlers): ProophProjector
    {
        if (null !== $this->handler || ! empty($this->handlers)) {
            throw new RuntimeException('When was already called');
        }

        foreach ($handlers as $eventName => $handler) {
            if (! is_string($eventName)) {
                throw new Exception\InvalidArgumentException('Invalid event name given, string expected');
            }

            if (! $handler instanceof Closure) {
                throw new Exception\InvalidArgumentException('Invalid handler given, Closure expected');
            }

            $this->handlers[$eventName] = Closure::bind($handler,
                $this->createHandlerContext($this->currentStreamName));
        }

        return $this;
    }

    public function whenAny(Closure $handler): ProophProjector
    {
        if (null !== $this->handler || ! empty($this->handlers)) {
            throw new RuntimeException('When was already called');
        }

        $this->handler = Closure::bind($handler, $this->createHandlerContext($this->currentStreamName));

        return $this;
    }

    public function emit(Message $event): void
    {
        if (! $this->streamCreated || ! $this->eventStore->hasStream(new StreamName($this->name))) {
            $this->eventStore->create(new Stream(new StreamName($this->name), new EmptyIterator()));
            $this->streamCreated = true;
        }

        $this->linkTo($this->name, $event);
    }

    public function linkTo(string $streamName, Message $event): void
    {
        $sn = new StreamName($streamName);

        if ($this->cachedStreamNames->has($streamName)) {
            $append = true;
        } else {
            $this->cachedStreamNames->rollingAppend($streamName);
            $append = $this->eventStore->hasStream($sn);
        }

        if ($append) {
            $this->eventStore->appendTo($sn, new ArrayIterator([$event]));
        } else {
            $this->eventStore->create(new Stream($sn, new ArrayIterator([$event])));
        }
    }

    public function reset(): void
    {
        $this->streamPositions = [];

        $callback = $this->initCallback;

        $this->state = [];

        if (is_callable($callback)) {
            $result = $callback();

            if (is_array($result)) {
                $this->state = $result;
            }
        }

        try {
            execute(
                $this->connection,
                [
                    [
                        404 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                UpdateDocument::with(
                    $this->projectionsTable,
                    $this->name,
                    [
                        'state' => $this->state,
                        'status' => ProjectionStatus::STOPPING()->getValue(),
                        'position' => $this->streamPositions,
                    ]
                )
            );
        } catch (ProjectionNotFound $exception) {
            // ignore
        }

        try {
            $this->eventStore->delete(new StreamName($this->name));
        } catch (Exception\StreamNotFound $exception) {
            // ignore
        }
    }

    public function stop(): void
    {
        $this->isStopped = true;
        try {
            execute(
                $this->connection,
                [
                    [
                        404 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                UpdateDocument::with(
                    $this->projectionsTable,
                    $this->name,
                    [
                        'status' => ProjectionStatus::IDLE()->getValue(),
                    ]
                )
            );
        } catch (ProjectionNotFound $exception) {
            // ignore
        }

        $this->status = ProjectionStatus::IDLE();
    }

    public function delete(bool $deleteEmittedEvents): void
    {
        try {
            execute(
                $this->connection,
                [
                    [
                        422 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                DeleteDocument::with(
                    $this->projectionsTable, [$this->name]
                )
            );
        } catch (ProjectionNotFound $exception) {
            // ignore
        }

        if ($deleteEmittedEvents) {
            try {
                $this->eventStore->delete(new StreamName($this->name));
            } catch (Exception\StreamNotFound $e) {
                // ignore
            }
        }

        $this->isStopped = true;

        $callback = $this->initCallback;

        $this->state = [];

        if (is_callable($callback)) {
            $result = $callback();

            if (is_array($result)) {
                $this->state = $result;
            }
        }

        $this->streamPositions = [];
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(bool $keepRunning = true): void
    {
        if (null === $this->query
            || (null === $this->handler && empty($this->handlers))
        ) {
            throw new RuntimeException('No handlers configured');
        }

        switch ($this->fetchRemoteStatus()) {
            case ProjectionStatus::STOPPING():
                $this->stop();

                return;
            case ProjectionStatus::DELETING():
                $this->delete(false);

                return;
            case ProjectionStatus::DELETING_INCL_EMITTED_EVENTS():
                $this->delete(true);

                return;
            case ProjectionStatus::RESETTING():
                $this->reset();
                break;
            default:
                break;
        }

        $this->createProjection();
        $this->acquireLock();

        $this->prepareStreamPositions();
        $this->load();

        $singleHandler = null !== $this->handler;

        $this->isStopped = false;

        try {
            do {
                foreach ($this->streamPositions as $streamName => $position) {
                    try {
                        $streamEvents = $this->eventStore->load(new StreamName($streamName), $position + 1);
                    } catch (Exception\StreamNotFound $e) {
                        // ignore
                        continue;
                    }

                    if ($singleHandler) {
                        $this->handleStreamWithSingleHandler($streamName, $streamEvents);
                    } else {
                        $this->handleStreamWithHandlers($streamName, $streamEvents);
                    }

                    if ($this->isStopped) {
                        break;
                    }
                }

                if (0 === $this->eventCounter) {
                    usleep($this->sleep);
                    $this->updateLock();
                } else {
                    $this->persist();
                }

                $this->eventCounter = 0;

                if ($this->triggerPcntlSignalDispatch) {
                    pcntl_signal_dispatch();
                }

                switch ($this->fetchRemoteStatus()) {
                    case ProjectionStatus::STOPPING():
                        $this->stop();
                        break;
                    case ProjectionStatus::DELETING():
                        $this->delete(false);
                        break;
                    case ProjectionStatus::DELETING_INCL_EMITTED_EVENTS():
                        $this->delete(true);
                        break;
                    case ProjectionStatus::RESETTING():
                        $this->reset();
                        break;
                    default:
                        break;
                }

                $this->prepareStreamPositions();
            } while ($keepRunning && ! $this->isStopped);
        } catch (ProjectionAlreadyExistsException $projectionAlreadyExistsException) {
            // throw it in finally
        } finally {
            if (isset($projectionAlreadyExistsException)) {
                throw $projectionAlreadyExistsException;
            }
            $this->releaseLock();
        }
    }

    private function fetchRemoteStatus(): ProjectionStatus
    {
        $query = ReadDocument::with($this->projectionsTable, $this->name);

        try {
            execute(
                $this->connection,
                [
                    [
                        404 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                $query
            );
        } catch (ProjectionNotFound $e) {
            // ignore
        }

        $result = $query->result();

        if (empty($result['status'])) {
            return ProjectionStatus::RUNNING();
        }

        return ProjectionStatus::byValue($result['status']);
    }

    private function handleStreamWithSingleHandler(string $streamName, Iterator $events): void
    {
        $this->currentStreamName = $streamName;
        $handler = $this->handler;

        foreach ($events as $event) {
            /* @var Message $event */
            $this->streamPositions[$streamName]++;
            $this->eventCounter++;

            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            if ($this->eventCounter === $this->persistBlockSize) {
                $this->persist();
                $this->eventCounter = 0;
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    private function handleStreamWithHandlers(string $streamName, Iterator $events): void
    {
        $this->currentStreamName = $streamName;

        foreach ($events as $event) {
            /* @var Message $event */
            $this->streamPositions[$streamName]++;

            if (! isset($this->handlers[$event->messageName()])) {
                continue;
            }

            $this->eventCounter++;

            $handler = $this->handlers[$event->messageName()];
            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            if ($this->eventCounter === $this->persistBlockSize) {
                $this->persist();
                $this->eventCounter = 0;
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    private function createHandlerContext(?string &$streamName)
    {
        return new class($this, $streamName) {
            /**
             * @var Projector
             */
            private $projector;

            /**
             * @var ?string
             */
            private $streamName;

            public function __construct(Projector $projector, ?string &$streamName)
            {
                $this->projector = $projector;
                $this->streamName = &$streamName;
            }

            public function stop(): void
            {
                $this->projector->stop();
            }

            public function linkTo(string $streamName, Message $event): void
            {
                $this->projector->linkTo($streamName, $event);
            }

            public function emit(Message $event): void
            {
                $this->projector->emit($event);
            }

            public function streamName(): ?string
            {
                return $this->streamName;
            }
        };
    }

    private function load(): void
    {
        $query = ReadDocument::with($this->projectionsTable, $this->name);

        try {
            execute(
                $this->connection,
                [
                    [
                        404 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                $query
            );
        } catch (ProjectionNotFound $e) {
            // ignore not found error
        }

        $result = $query->result();

        if (isset($result['position'], $result['state'])) {
            $this->streamPositions = array_merge($this->streamPositions, $result['position']);
            $state = $result['state'];

            if (! empty($state)) {
                $this->state = $state;
            }
        }
    }

    private function createProjection(): void
    {
        try {
            execute(
                $this->connection,
                [
                    [
                        400 => [ProjectionNotCreatedException::class, $this->name],
                        404 => [ProjectionNotCreatedException::class, $this->name],
                        409 => [ProjectionAlreadyExistsException::class, $this->name],
                    ],
                ],
                InsertDocument::with(
                    $this->projectionsTable,
                    [
                        [
                            '_key' => $this->name,
                            'position' => (object) null,
                            'state' => (object) null,
                            'status' => $this->status->getValue(),
                            'locked_until' => null,
                        ],
                    ]
                )
            );
        } catch (ProjectionNotCreatedException | ProjectionAlreadyExistsException $execption) {
            // we ignore any occurring error here (duplicate projection)
        }
    }

    private function acquireLock(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d\TH:i:s.u');

        $lockUntilString = $this->createLockUntilString($now);

        $aql = <<<'EOF'
FOR c IN @@collection
FILTER c._key == @name AND (c.locked_until == null OR c.locked_until < @nowString)
UPDATE c WITH 
{ 
    locked_until: @lockedUntil, 
    status: @status
} 
IN @@collection
RETURN NEW
EOF;

        $statement = new Statement(
            $this->connection, [
                Statement::ENTRY_QUERY => $aql,
                Statement::ENTRY_BINDVARS => [
                    '@collection' => $this->projectionsTable,
                    'name' => $this->name,
                    'lockedUntil' => $lockUntilString,
                    'nowString' => $nowString,
                    'status' => ProjectionStatus::RUNNING()->getValue(),
                ],
                Cursor::ENTRY_FLAT => true,
            ]
        );

        try {
            $result = $statement->execute();

            if ($result->getCount() === 0) {
                throw new Exception\RuntimeException('Another projection process is already running');
            }
        } catch (\ArangoDBClient\ServerException $e) {
            if ($e->getCode() === 404) {
                throw RuntimeException::fromServerException($e);
            }
            throw $e;
        }

        $this->status = ProjectionStatus::RUNNING();
    }

    private function updateLock(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $lockUntilString = $this->createLockUntilString($now);

        execute(
            $this->connection,
            [
                [
                    404 => [ProjectionNotFound::class, $this->name],
                ],
            ],
            UpdateDocument::with(
                $this->projectionsTable,
                $this->name,
                [
                    'locked_until' => $lockUntilString,
                ]
            )
        );
    }

    private function releaseLock(): void
    {
        try {
            execute(
                $this->connection,
                [
                    [
                        404 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                UpdateDocument::with(
                    $this->projectionsTable,
                    $this->name,
                    [
                        'status' => ProjectionStatus::IDLE()->getValue(),
                        'locked_until' => null,
                    ]
                )
            );
        } catch (ProjectionNotFound $exception) {
            //  ignore not found error
        }

        $this->status = ProjectionStatus::IDLE();
    }

    private function persist(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $lockUntilString = $this->createLockUntilString($now);

        try {
            execute(
                $this->connection,
                [
                    [
                        404 => [ProjectionNotFound::class, $this->name],
                    ],
                ],
                UpdateDocument::with(
                    $this->projectionsTable,
                    $this->name,
                    [
                        'position' => $this->streamPositions,
                        'state' => $this->state,
                        'locked_until' => $lockUntilString,
                    ]
                )
            );
        } catch (ProjectionNotFound $exception) {
            //  ignore not found errorF
        }
    }

    private function prepareStreamPositions(): void
    {
        $streamPositions = [];

        if (isset($this->query['all'])) {
            $aql = <<<'EOF'
FOR c IN  @@collection
FILTER c.real_stream_name !~ '^\\$'
RETURN {
    "real_stream_name": c.real_stream_name
}
EOF;
            $statement = new Statement(
                $this->connection, [
                    Statement::ENTRY_QUERY => $aql,
                    Statement::ENTRY_BINDVARS => [
                        '@collection' => $this->eventStreamsTable,
                    ],
                    Cursor::ENTRY_FLAT => true,
                ]
            );

            foreach ($statement->execute() as $streamName) {
                $streamPositions[$streamName['real_stream_name']] = 0;
            }

            $this->streamPositions = array_merge($streamPositions, $this->streamPositions);

            return;
        }

        if (isset($this->query['categories'])) {
            $aql = <<<'EOF'
FOR c IN  @@collection
FILTER c.category IN @categories
RETURN {
    "real_stream_name": c.real_stream_name
}
EOF;
            $statement = new Statement(
                $this->connection, [
                    Statement::ENTRY_QUERY => $aql,
                    Statement::ENTRY_BINDVARS => [
                        '@collection' => $this->eventStreamsTable,
                        'categories' => $this->query['categories'],
                    ],
                    Cursor::ENTRY_FLAT => true,
                ]
            );

            foreach ($statement->execute() as $streamName) {
                $streamPositions[$streamName['real_stream_name']] = 0;
            }

            $this->streamPositions = array_merge($streamPositions, $this->streamPositions);

            return;
        }

        // stream names given
        foreach ($this->query['streams'] as $streamName) {
            $streamPositions[$streamName] = 0;
        }

        $this->streamPositions = array_merge($streamPositions, $this->streamPositions);
    }

    private function createLockUntilString(DateTimeImmutable $from): string
    {
        $micros = (string) ((int) $from->format('u') + ($this->lockTimeoutMs * 1000));

        $secs = substr($micros, 0, -6);

        if ('' === $secs) {
            $secs = 0;
        }

        $resultMicros = substr($micros, -6);

        return $from->modify('+' . $secs . ' seconds')->format('Y-m-d\TH:i:s') . '.' . $resultMicros;
    }
}