<?php

declare(strict_types=1);

use function Onion\Framework\Loop\{
    coroutine,
    scheduler,
    tick
};

if (!defined('EVENT_LOOP_AUTOSTART')) {
    define('EVENT_LOOP_AUTOSTART', true);
}

if (!defined('EVENT_LOOP_HANDLE_SIGNALS')) {
    define('EVENT_LOOP_HANDLE_SIGNALS', true);
}

if (EVENT_LOOP_AUTOSTART) {
    register_shutdown_function(scheduler()->start(...));
}

if (EVENT_LOOP_HANDLE_SIGNALS) {
    $triggered = false;
    $signalHandler = function (int $event) use (&$triggered) {
        if (
            (defined('PHP_WINDOWS_EVENT_CTRL_C') &&
                ($event === constant('PHP_WINDOWS_EVENT_CTRL_C') || $event === constant('PHP_WINDOWS_EVENT_CTRL_BREAK'))
            ) ||
            (defined('SIGINT') && $event === constant('SIGINT'))
        ) {
            if ($triggered) {
                fwrite(STDERR, "\nForcing termination by user request.\n");
                exit(match (strtolower(PHP_OS_FAMILY)) {
                    'windows' => 0,
                    default => 130,
                });
            }
            $triggered = true;

            fwrite(STDOUT, "\nAttempting graceful termination by user request, repeat to force.\n");
            coroutine(
                function () {
                    scheduler()->stop();
                    tick();

                    exit(match (strtolower(PHP_OS_FAMILY)) {
                        'windows' => 0,
                        default => 130,
                    });
                }
            );
        }
    };

    if (strtolower(PHP_OS_FAMILY) == 'windows') {
        sapi_windows_set_ctrl_handler($signalHandler, true);
    } elseif (extension_loaded('pcntl')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $signalHandler);
    }
}
