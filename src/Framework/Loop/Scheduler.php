<?php
namespace Onion\Framework\Loop;

class Scheduler implements \Countable
{
    protected $taskMap = [];

    protected $taskQueue;

    private $reads = [];
    private $readTask = [];

    private $writes = [];

    private $started = false;

    public function __construct() {
        $this->taskQueue = new \SplQueue();
    }

    public function push(\Generator $coroutine) {

        $task = self::makeTask($coroutine);
        $this->taskMap[$task->getId()] = $task;
        $this->schedule($task);
        return $task->getId();
    }

    public function poll($timeout) {
        $reads = [];
        foreach ($this->reads as list($sock)) {
            if ($sock && is_resource($sock)) {
                $reads[] = $sock;
            }
        }

        $writes = [];
        foreach ($this->writes as list($sock)) {
            if ($sock && is_resource($sock)) {
                $writes[] = $sock;
            }
        }

        $errors = [];

        if (empty($reads) && empty($writes)) {
            return;
        }


        if (@stream_select($reads, $writes, $errors, $timeout) !== false) {
            foreach ($reads as $read) {
                $fd = (int) $read;
                list(, $tasks) = $this->reads[$fd];

                unset($this->reads[$fd]);
                foreach ($tasks as $task) {
                    $this->schedule($task);
                }
            }

            foreach ($writes as $write) {
                $fd = (int) $write;
                list(, $tasks) = $this->writes[$fd];
                unset($this->writes[$fd]);

                foreach ($tasks as $task) {
                    $this->schedule($task);
                }
            }
        }
    }

    public function schedule(Task $task) {
        $this->taskQueue->enqueue($task);
    }

    public function start()
    {
        $this->started = true;


        while ($this->started) {
            $this->run();
        }
    }

    public function run() {
        $this->started = true;
        $this->push((function () {
            while (true) {
                if ($this->taskQueue->isEmpty()) {
                    $this->poll(null);
                } else {
                    $this->poll(0);
                }
                yield;
            }
        })());

        while (!$this->taskQueue->isEmpty()) {
            $task = $this->taskQueue->dequeue();
            $result = $task->run();

            if ($result instanceof Signal) {
                try {
                    $result($task, $this);
                } catch (\Throwable $ex) {
                    $task->setException($ex);
                    $this->schedule($task);
                }
            }

            if ($task->isFinished()) {
                unset($this->taskMap[$task->getId()]);
            } else {
                $this->schedule($task);
            }
        }
    }

    public function attach($socket, ?Task $onRead = null, ?Task $onWrite = null)
    {
        $fd = (int) $socket;
        if ($onRead !== null) {
            if (!isset($this->reads[$fd])) {
                $this->reads[$fd] = [$socket, [$onRead,]];
            } else {
                $this->reads[$fd][1][] = $onRead;
            }
        }

        if ($onWrite !== null) {
            if (!isset($this->writes[$fd])) {
                $this->writes[$fd] = [$socket, [$onWrite,]];
            } else {
                $this->writes[$fd][1][] = $onWrite;
            }
        }
    }

    public function kill($tid) {
        if (!isset($this->taskMap[$tid])) {
            return false;
        }

        unset($this->taskMap[$tid]);

        // This is a bit ugly and could be optimized so it does not have to walk the queue,
        // but assuming that killing tasks is rather rare I won't bother with it now
        foreach ($this->taskQueue as $i => $task) {
            if ($task->getId() === $tid) {
                unset($this->taskQueue[$i]);
                break;
            }
        }

        return true;
    }

    public function count()
    {
        return $this->taskQueue->count();
    }

    public static function makeTask(\Generator $coroutine)
    {
        return new Task($coroutine);
    }
}
