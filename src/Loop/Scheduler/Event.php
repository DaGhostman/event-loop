<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;

use EventBase,

Event as TaskEvent;
use EventBufferEvent;
use EventListener;
use EventConfig;

use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Scheduler\Traits\{SchedulerErrorHandler, StreamNetworkUtil};
use Throwable;

use Onion\Framework\Server\Interfaces\ContextInterface as ServerContext;
use Onion\Framework\Client\Interfaces\ContextInterface as ClientContext;
use Onion\Framework\Loop\Resources\CallbackStream;
use Closure;
use EventSslContext;

use Onion\Framework\Loop\Types\NetworkProtocol;
use Onion\Framework\Loop\Types\NetworkAddress;

use function Onion\Framework\Loop\signal;

class Event implements SchedulerInterface
{
    use SchedulerErrorHandler;
    use StreamNetworkUtil {
        open as private nativeOpen;
        connect as private nativeConnect;
    }

    private EventBase $base;
    private array $tasks = [];
    private array $sockets = [];
    private array $buffers = [];


    private array $listeners = [];
    private array $readTasks = [];

    private array $writeTasks = [];

    private bool $started = false;

    private bool $supportsLocalFiles = true;

    public function __construct()
    {
        $config = new EventConfig();
        $config->requireFeatures(EventConfig::FEATURE_FDS | EventConfig::FEATURE_ET | EventConfig::FEATURE_O1);


        $handler = set_error_handler(fn ($errno, $err) => throw new \RuntimeException($err, $errno));
        try {
            $this->base = new EventBase($config);
        } catch (\Throwable) {
            $this->supportsLocalFiles = false;
            $config->requireFeatures(EventConfig::FEATURE_ET | EventConfig::FEATURE_O1);
            $this->base = new EventBase($config);
        } finally {
            set_error_handler($handler);
        }
    }


    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($this->base->gotStop()) {
            return;
        }

        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            -1,
            TaskEvent::TIMEOUT,
            function ($fd, $what, TaskInterface $task) use ($key) {
                $this->tasks[$key]->free();
                unset($this->tasks[$key]);

                if ($task->isKilled() || $task->isFinished()) {
                    return;
                }

                try {
                    $result = $task->run();

                    if ($result instanceof Signal) {
                        $this->schedule(Task::create(Closure::fromCallable($result), [$task, $this]));
                        return;
                    }

                    if (
                        !$task->isKilled() &&
                        $task->isFinished() &&
                        $task->isPersistent()
                    ) {
                        $this->schedule($task->spawn());
                    }

                    $this->schedule($task);
                } catch (Throwable $ex) {
                    $this->triggerErrorHandlers($ex);
                }
            },
            $task,
        ))->add($at !== null ? ($at - (hrtime(true) / 1e+3)) / 1e+6 : 0);

        $this->tasks[$key] = $event;
    }

    private function triggerTasks(int $fd, int $what): void
    {
        if ($what & TaskEvent::READ) {
            foreach ($this->readTasks[$fd] ?? [] as $idx => $task) {
                if (!$task->isPersistent()) {
                    unset($this->readTasks[$fd][$idx]);
                }

                $this->schedule($task->isPersistent() ? $task->spawn(false) : $task);
            }
        }

        if ($what & TaskEvent::WRITE) {
            foreach ($this->writeTasks[$fd] ?? [] as $idx => $task) {
                if (!$task->isPersistent()) {
                    unset($this->writeTasks[$fd][$idx]);
                }

                $this->schedule($task->isPersistent() ? $task->spawn(false) : $task);
            }
        }

        if (count($this->readTasks[$fd] ?? []) === 0 && count($this->writeTasks[$fd] ?? []) === 0) {
            if (isset($this->listeners[$fd])) {
                $this->listeners[$fd] instanceof TaskInterface ?
                    $this->listeners[$fd]->kill() :$this->listeners[$fd]?->free();
                unset($this->listeners[$fd]);
            }
        }
    }

    private function register(int $fd, mixed $resource): void
    {
        if (!$this->supportsLocalFiles && !is_int($resource)) {
            /**
             * Necessary to have all local files & php internal streams
             * be readable & writeable at the same time in order to prevent
             * possible issues with `epoll` & deadlocks with `poll`
             * Therefore all such streams are considered readable & writable
             * at the same time
             */

            $this->listeners[$fd] = Task::create($this->triggerTasks(...), [$fd, TaskEvent::READ | TaskEvent::WRITE], persistent: true);
            $this->schedule($this->listeners[$fd]);
            return;
        }

        if (!isset($this->listeners[$fd])) {
            ($this->listeners[$fd] = new TaskEvent(
                $this->base,
                is_resource($resource) ? \EventUtil::getSocketFd($resource) : $resource,
                TaskEvent::READ | TaskEvent::WRITE | TaskEvent::PERSIST | TaskEvent::ET,
                $this->triggerTasks(...),
            ))->add();
        }
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        $this->register($resource->getResourceId(), $resource->getResource());
        $this->readTasks[$resource->getResourceId()][] = $task;
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        $this->register($resource->getResourceId(), $resource->getResource());
        $this->writeTasks[$resource->getResourceId()][] = $task;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->base->loop();
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        $this->base->stop();
    }

    public function signal(int $signal, TaskInterface $task, int $priority = 0): void
    {
        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            $signal,
            TaskEvent::SIGNAL | TaskEvent::PERSIST,
            function ($fd, $what, TaskInterface $task) use ($key) {
                $this->tasks[$key]->free();
                unset($this->tasks[$key]);

                $this->schedule($task);
            },
            $task,
        ))->add();

        $this->tasks[$key] = $event;
    }

    public function open(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ?ServerContext $context = null,
        NetworkAddress $type = NetworkAddress::NETWORK,
    ): string {
        if ($protocol !== NetworkProtocol::TCP && $type !== NetworkAddress::NETWORK) {
            return $this->nativeOpen($address, $port, $callback, $protocol, $context, $type);
        }

        $context = $context?->getContextArray() ?? [];
        ($event = new EventListener(
            $this->base,
            function (EventListener $listener, $fd, array $address, $dispatchFunction) use ($context) {

                $bev = new EventBufferEvent(
                    $this->base,
                    $fd,
                    EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS,
                );

                if (isset($context['ssl'])) {
                    // we can natively support SSL sockets
                    $contextOptions = array_filter([
                        EventSslContext::OPT_VERIFY_PEER => $context['ssl']['verify_peer'] ?? null,
                        EventSslContext::OPT_VERIFY_DEPTH => $context['ssl']['verify_depth'] ?? null,
                        EventSslContext::OPT_ALLOW_SELF_SIGNED => $context['ssl']['allow_self_signed'] ?? null,
                        EventSslContext::OPT_LOCAL_CERT => $context['ssl']['local_cert'] ?? null,
                        EventSslContext::OPT_LOCAL_PK => $context['ssl']['local_pk'] ?? null,
                        EventSslContext::OPT_PASSPHRASE => $context['ssl']['passphrase'] ?? null,
                    ]);

                    $ctx = new EventSslContext(EventSslContext::TLS_SERVER_METHOD, $contextOptions);

                    $bev = EventBufferEvent::sslSocket(
                        $this->base,
                        $fd,
                        $ctx,
                        EventBufferEvent::SSL_ACCEPTING,
                        EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS
                    );
                }

                $this->schedule(Task::create($dispatchFunction, [new CallbackStream(
                    static fn (int $size) => signal(fn ($resume) => $resume($bev->fd !== null ? $bev->read($size) : false)),
                    static fn () => $bev->fd === null,
                    static fn (string $data) => signal(fn ($resume) => $resume($bev->fd !== null ? ($bev->write($data) ? strlen($data) : false) : false)),
                    $bev->free(...),
                    $bev->fd,
                    $bev->fd,
                )]));

                $bev->setCallbacks(
                    fn (EventBufferEvent $bev) => $this->schedule(Task::create($this->triggerTasks(...), [$bev->fd, TaskEvent::READ])),
                    fn (EventBufferEvent $bev) => $this->schedule(Task::create($this->triggerTasks(...), [$bev->fd, TaskEvent::WRITE])),
                    function (EventBufferEvent $bev, int $events) {
                        if ($events & EventBufferEvent::EOF) {
                            $bev->free();
                            unset($this->buffers[$bev->fd]);
                        }
                    },
                    $dispatchFunction
                );

                $bev->enable(TaskEvent::READ | TaskEvent::WRITE);
                $this->buffers[$fd] = $bev;
            },
            $callback,
            EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
            -1,
            "{$address}:{$port}",
        ));

        $this->sockets[spl_object_id($event)] = $event;
        $event->getSocketName($addr, $p);

        return "{$addr}:{$p}";
    }

    public function connect(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ?ClientContext $context = null,
        NetworkAddress $type = NetworkAddress::NETWORK,
    ): void {
        if ($protocol === NetworkProtocol::UDP) {
            $this->nativeConnect($address, $port, $callback, $protocol, $context, $type);
            return;
        }

        $bev = new EventBufferEvent(
            $this->base,
            null,
            EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS,
        );

        $contextArray = $context?->getContextArray() ?? [];
        if (isset($contextArray['ssl'])) {
            $contextOptions = array_filter([
                EventSslContext::OPT_VERIFY_PEER => $contextArray['ssl']['verify_peer'] ?? null,
                EventSslContext::OPT_VERIFY_DEPTH => $contextArray['ssl']['verify_depth'] ?? null,
                EventSslContext::OPT_ALLOW_SELF_SIGNED => $contextArray['ssl']['allow_self_signed'] ?? null,
                EventSslContext::OPT_LOCAL_CERT => $contextArray['ssl']['local_cert'] ?? null,
                EventSslContext::OPT_LOCAL_PK => $contextArray['ssl']['local_pk'] ?? null,
                EventSslContext::OPT_PASSPHRASE => $contextArray['ssl']['passphrase'] ?? null,
            ]);

            $ctx = new EventSslContext(EventSslContext::TLS_CLIENT_METHOD, $contextOptions);

            $bev = EventBufferEvent::sslSocket(
                $this->base,
                null,
                $ctx,
                EventBufferEvent::SSL_CONNECTING,
                EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS
            );
        }

        $bev->setCallbacks(
            fn (EventBufferEvent $bev) => $this->schedule(Task::create($this->triggerTasks(...), [$bev->fd, TaskEvent::READ])),
            fn (EventBufferEvent $bev) => $this->schedule(Task::create($this->triggerTasks(...), [$bev->fd, TaskEvent::WRITE])),
            function (EventBufferEvent $bev, int $events, Closure $callback) {
                if ($events & EventBufferEvent::EOF) {
                    $bev->free();
                    unset($this->buffers[$bev->fd]);
                } elseif ($events & EventBufferEvent::CONNECTED) {
                    $this->schedule(Task::create($callback, [new CallbackStream(
                        static fn (int $size) => signal($bev->fd !== null ? fn ($resume) => $resume($bev->read($size)) : false),
                        static fn () => $bev->fd === null,
                        static fn (string $data) => signal(fn ($resume) => $bev->fd !== null ? ($bev->write($data) ? strlen($data) : false) : false),
                        $bev->free(...),
                        $bev->fd,
                        $bev->fd,
                    )], false));
                }
            },
            $callback
        );

        $bev->enable(TaskEvent::READ | TaskEvent::WRITE);
        $bev->connect(match ($type) {
            NetworkAddress::NETWORK => (stripos($address, '::') !== false ? "[{$address}]" : $address) . ":{$port}",
            NetworkAddress::LOCAL => $address,
        });

        $this->buffers[$bev->fd] = $bev;
    }
}
