<?php

declare(strict_types=1);

use function Onion\Framework\Loop\{
    coroutine,
    scheduler,
    tick
};

if (!defined('EVENT_LOOP_AUTOSTART')) {
    /**
     * Should the event loop auto-start or would require explicit
     * trigger by the user. Defaults to `true`
     *
     * @var bool `true` to enable, `false` otherwise
     */
    define('EVENT_LOOP_AUTOSTART', true);
}

if (!defined('EVENT_LOOP_HANDLE_SIGNALS')) {
    /**
     * Use internal signal handler that is aware of the event
     * loop. Defaults to `true`
     *
     * @var bool `true` to enable, `false` otherwise
     */
    define('EVENT_LOOP_HANDLE_SIGNALS', true);
}

if (!defined('EVENT_LOOP_STREAM_IDLE_TIMEOUT')) {
    /**
     * A default timeout block the event loop if there are no tasks
     * or timers pending, specifically in situations where the server
     * is waiting for connections, etc. This would allow near instant
     * scheduling (based on the `EVENT_LOOP_STREAM_IDLE_TIMEOUT`, with
     * the default 1s it'd be as close as ~1s correct trigger).
     *
     * An obvious candidate would be implementation of a cron-like
     * functionality without leaving the application scope.
     *
     * Defaults to 1s
     * @var int timeout in microseconds.
     */
    define('EVENT_LOOP_STREAM_IDLE_TIMEOUT', 1_000_000);
}

if (EVENT_LOOP_AUTOSTART) {
    register_shutdown_function(fn () => scheduler()->start());
}

if (EVENT_LOOP_HANDLE_SIGNALS) {
    if (!defined('CTRL_C')) {
        if (defined('PHP_WINDOWS_EVENT_CTRL_C')) {
            define('CTRL_C', PHP_WINDOWS_EVENT_CTRL_C);
        } else if (defined('SIGINT')) {
            define('CTRL_C', SIGINT);
        } else {
            define('CTRL_C', 0);
        }
    }

    scheduler()->signal(CTRL_C, \Onion\Framework\Loop\Task::create(function () {
        fwrite(STDOUT, "\nAttempting graceful termination by user request.\n");

        scheduler()->stop();

        exit(128 + CTRL_C);
    }));
}
