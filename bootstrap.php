<?php

declare(strict_types=1);

use function Onion\Framework\Loop\{scheduler};

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

if (!defined('EVENT_LOOP_TRACE_TASKS')) {
    /**
     * (Experimental) Enable tracing of tasks, this would allow
     * more accurate stack traces that should ignore the internal
     * components of the event loop.
     *
     * Note that this functionality is experimental and may not
     * work 100% as expected or add some performance cost as it
     * heavily relies on `debug_backtrace`.
     */
    define('EVENT_LOOP_TRACE_TASKS', filter_var(
        getenv('TRACE_TASKS') ?: '1', // Handle undefined env var as true
        FILTER_VALIDATE_BOOLEAN,
        ['flags' => FILTER_NULL_ON_FAILURE]
    // in case of invalid value, fallback to false as it is enabled by default
    ) ?? false);
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

if (function_exists('uv_loop_init') && !function_exists('uv_loop_new')) {
    /**
     * Handle possible removal of `uv_loop_new` in future versions of
     * `symplely/uv-ffi` by providing a fallback to `uv_loop_init`
     * whilst maintaining backwards compatibility with the pecl `uv`
     * extension.
     */

    function uv_loop_new(): mixed
    {
        return uv_loop_init();
    }
}
