<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use EventBase, Event as TaskEvent;
use EventBufferEvent;
use EventListener;
use EventConfig;

use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Scheduler\Traits\{SchedulerErrorHandler, StreamNetworkUtil};
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkServerAwareSchedulerInterface;
use Onion\Framework\Loop\Resources\Buffer;
use Throwable;

use function Onion\Framework\Loop\suspend;
use Onion\Framework\Server\Interfaces\ContextInterface as ServerContext;
use Onion\Framework\Client\Interfaces\ContextInterface as ClientContext;
use Onion\Framework\Loop\Resources\CallbackStream;
use Closure;
use Onion\Framework\Loop\Scheduler\Types\NetworkProtocol;
use Onion\Framework\Loop\Scheduler\Types\NetworkAddressType;
use EventSslContext;
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkClientAwareSchedulerInterface;

class Event implements SchedulerInterface, NetworkServerAwareSchedulerInterface, NetworkClientAwareSchedulerInterface
{
    private EventBase $base;
    private array $tasks = [];
    private array $sockets = [];
    private array $buffers = [];

    private bool $started = false;

    use SchedulerErrorHandler;
    use StreamNetworkUtil {
        open as private nativeOpen;
    }

    public function __construct()
    {
        $config = new EventConfig();
        $config->requireFeatures(EventConfig::FEATURE_FDS);
        $config->requireFeatures(EventConfig::FEATURE_O1);

        $this->base = new EventBase($config);
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
                if (!$task->isPersistent()) {
                    $this->tasks[$key]->free();
                    unset($this->tasks[$key]);
                }

                if ($task->isKilled()) {
                    return;
                }

                if ($task->isPersistent()) {
                    $task = $task->spawn();
                }

                try {
                    $result = $task->run();

                    if ($result instanceof Signal) {
                        try {
                            $this->schedule(Task::create(\Closure::fromCallable($result), [$task, $this]));
                        } catch (Throwable $ex) {
                            if (!$task->throw($ex)) {
                                $this->triggerErrorHandlers($ex);
                            }
                        }
                        return;
                    }
                } catch (Throwable $ex) {
                    $this->triggerErrorHandlers($ex);
                }

                if (!$task->isFinished()) {
                    $this->schedule($task);
                }
            },
            $task,
        ))->add($at !== null ? ($at - (hrtime(true) / 1e+3)) / 1e+6 : 0);

        $this->tasks[spl_object_id($task)] = $event;
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->eof()) {
            return;
        }

        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            $resource->getResource(),
            TaskEvent::READ,
            function ($fd, $what, TaskInterface $task) use ($key) {
                if (!$task->isPersistent()) {
                    $this->tasks[$key]->free();
                    unset($this->tasks[$key]);
                }

                $this->schedule($task->isPersistent() ? $task->spawn() : $task);
            },
            $task,
        ))->add();

        $this->tasks[$key] = $event;
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->eof()) {
            return;
        }

        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            $resource->getResource(),
            TaskEvent::WRITE,
            function ($fd, $what, TaskInterface $task) use ($key) {
                if (!$task->isPersistent()) {
                    $this->tasks[$key]->free();
                    unset($this->tasks[$key]);
                }

                $this->schedule($task->isPersistent() ? $task->spawn() : $task);
            },
            $task,
        ))->add();

        $this->tasks[$key] = $event;
    }

    public function start(): void {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->base->loop();
    }

    public function stop(): void {
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
        NetworkAddressType $type = NetworkAddressType::NETWORK,
    ): string {
        if ($protocol !== NetworkProtocol::TCP && $type !== NetworkAddressType::NETWORK) {
            return $this->nativeOpen($address, $port, $callback, $protocol, $context, $type);
        }

        $context = $context?->getContextArray() ?? [];
        ($event = new EventListener(
            $this->base,
            function ($listener, $fd, array $address, $dispatchFunction) use ($context) {

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

                $bev->setCallbacks(function (EventBufferEvent $bev, $dispatchFunction) {
                        $buffer = new Buffer();
                        while ($chunk = $bev->read(65535)) {
                            if (!$chunk) {
                                break;
                            }

                            $buffer->write($chunk);
                            suspend();
                        }

                        $output = new CallbackStream(
                            $bev->read(...),
                            $bev->write(...),
                            $bev->close(...),
                        );
                        $this->schedule(Task::create($dispatchFunction, [$buffer, $output]));
                    },
                    fn () => null,
                    function (EventBufferEvent $bev, int $events) {
                        if ($events & EventBufferEvent::EOF) {
                            $bev->free();
                            unset($this->buffers[$bev->fd]);
                        }
                     },
                    $dispatchFunction);

                $bev->enable(TaskEvent::READ | TaskEvent::WRITE);
                $this->buffers[$fd] = $bev;
            },
            $callback,
            EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
            -1,
            $address,
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
        NetworkAddressType $type = NetworkAddressType::NETWORK,
    ): void
    {

    }
}
