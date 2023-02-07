<?php

declare(strict_types=1);

namespace Onion\Framework;

use Closure;

use function Onion\Framework\Loop\{
    coroutine,
    scheduler,
    signal,
    tick
};

if (!defined('EVENT_LOOP_AUTOSTART')) {
    define('EVENT_LOOP_AUTOSTART', true);
}

if (!defined('EVENT_LOOP_HANDLE_SIGNALS')) {
    define('EVENT_LOOP_HANDLE_SIGNALS', true);
}

if (!class_exists(FileStreamWrapper::class)) {
    /**
     * Transform file streams asynchronous transparently for the underlying
     * code by utilizing the event loop scheduler. This does not happen with
     * PHP files because the composer auto-loading breaks when a file is
     * requested more than once prior to the completion of the including
     * coroutine (race condition between inclusion and concurrent server
     * requests for example).
     */
    class FileStreamWrapper
    {
        private $resource;
        private $directory;

        private bool $reportErrors = false;

        public static function register()
        {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', static::class);
        }
        public static function unregister()
        {
            stream_wrapper_unregister('file');
            stream_wrapper_restore('file');
        }

        private function wrap(Closure $callback, mixed...$args)
        {
            self::unregister();
            $result = @$callback(...$args);
            self::register();

            return $result;
        }

        public function dir_closedir(): bool
        {
            $this->wrap(closedir(...), $this->directory);

            return $this->directory === false;
        }

        public function dir_opendir(string $path, int $options = null): bool
        {
            return ($this->directory = $this->wrap(opendir(...), $path, null)) !== false;
        }

        public function dir_readdir(): string|false
        {
            return $this->wrap(readdir(...), $this->directory);
        }

        public function dir_rewinddir(): bool
        {
            $this->wrap(rewinddir(...), $this->directory);

            return true;
        }

        public function mkdir(string $path, $mode, int $options = 0): bool
        {
            return $this->wrap(mkdir(...), $path, $mode, ($options & STREAM_MKDIR_RECURSIVE) === STREAM_MKDIR_RECURSIVE);
        }

        public function rename(string $from, string $to): bool
        {
            return $this->wrap(rename(...), $from, $to);
        }

        public function rmdir(string $path): bool
        {
            return $this->wrap(rmdir(...), $path);
        }

        public function stream_open(
            string $path,
            string $mode,
            int $options,
            ?string &$opened_path,
        ): bool
        {
            if (substr($path, -4, 4) !== '.php') {
                $path = 'async://' . $path;
            }

            $this->resource = $this->wrap(fopen(...), $path, $mode);

            $this->reportErrors = ($options & STREAM_REPORT_ERRORS) === STREAM_REPORT_ERRORS;
            if (!$this->resource) {
                if ($this->reportErrors) {
                    trigger_error("Unable to open stream {$path}", E_USER_ERROR);
                }

                return false;
            }

            if (($options & STREAM_USE_PATH) === STREAM_USE_PATH) {
                $opened_path = $path;
            }


            return $this->resource !== false;
        }

        public function stream_cast(int $as): mixed
        {
            return $this->resource ?
                $this->resource : false;
        }

        public function stream_close()
        {
            $this->wrap(fclose(...), $this->resource);
            $this->resource = false;
        }

        public function stream_eof(): bool
        {
            return $this->wrap(feof(...), $this->resource);
        }

        public function stream_flush(): bool
        {
            return $this->wrap(fflush(...), $this->resource);
        }

        public function stream_lock(int $operation): bool
        {
            if ($operation === 0) {
                return true;
            }

            return $this->wrap(flock(...), $this->resource, $operation);
        }

        public function stream_metadata(string $path, int $option, mixed $value): bool
        {
            return match ($option) {
                STREAM_META_TOUCH => empty($value) ? $this->wrap(touch(...), $path, $value[0] ?? null, $value[1] ?? null) : $this->wrap(touch(...), $path),
                STREAM_META_OWNER => $this->wrap(chown(...), $path, $value),
                STREAM_META_OWNER_NAME => $this->wrap(chown(...), $path, $value),
                STREAM_META_GROUP => $this->wrap(chgrp(...), $path, $value),
                STREAM_META_ACCESS => $this->wrap(chmod(...), $path, $value),
                default => false,
            };
        }

        public function stream_read(int $count): string|false
        {
            return $this->wrap(fread(...), $this->resource, $count);
        }

        public function stream_seek(int $offset, int $whence = SEEK_SET): bool
        {
            return $this->wrap(fseek(...), $this->resource, $offset, $whence) === 0;
        }

        public function stream_set_option(int $option, ?int $arg1 = null, ?int $arg2 = null): bool
        {
            return match ($option) {
                STREAM_OPTION_BLOCKING => $this->wrap(stream_set_blocking(...), $this->resource, $arg1),
                STREAM_OPTION_READ_TIMEOUT => $this->wrap(stream_set_timeout(...), $this->resource, $arg1, $arg2),
                STREAM_OPTION_WRITE_BUFFER => $this->wrap(stream_set_write_buffer(...), $this->resource, $arg2) === 0,
                STREAM_OPTION_READ_BUFFER => $this->wrap(stream_set_read_buffer(...), $this->resource, $arg2) === 0,
                default => false,
            };
        }

        public function stream_stat(): array |false
        {
            return $this->wrap(fstat(...), $this->resource);
        }

        public function stream_tell(): int
        {
            return $this->wrap(ftell(...), $this->resource);
        }

        public function stream_truncate(int $size): bool
        {
            return $this->wrap(ftruncate(...), $this->resource, $size);
        }

        public function stream_write(string $data): int
        {
            return $this->wrap(fwrite(...), $this->resource, $data);
        }

        public function unlink(string $path): bool
        {
            return $this->wrap(unlink(...), $path);
        }

        public function url_stat(string $path, int $flags): array |false
        {
            return (($flags & STREAM_URL_STAT_LINK) === STREAM_URL_STAT_LINK) ?
                $this->wrap(lstat(...), $path) :
                $this->wrap(stat(...), $path);
        }
    }
    FileStreamWrapper::register();
}

if (!class_exists(AsyncStreamWrapper::class)) {
    /**
     * The actual async stream handling performed to allow differentiating
     * between regular
     */
    class AsyncStreamWrapper
    {
        private $resource;
        private $directory;

        private $reportErrors;

        public static function register()
        {
            stream_wrapper_register('async', static::class);
        }
        public static function unregister()
        {
            stream_wrapper_unregister('async');
        }

        private function async(Closure $fn, mixed...$args): mixed
        {
            return signal(fn($resume) => $resume(@$fn(...$args)));
        }

        public function dir_closedir(): bool
        {
            $this->async(closedir(...), $this->directory);

            return $this->directory === false;
        }

        public function dir_opendir(string $path, int $options = null): bool
        {
            return ($this->directory = $this->async(opendir(...), $path, null)) !== false;
        }

        public function dir_readdir(): string|false
        {
            return $this->async(readdir(...), $this->directory);
        }

        public function dir_rewinddir(): bool
        {
            $this->async(rewinddir(...), $this->directory);

            return true;
        }

        public function mkdir(string $path, $mode, int $options = 0): bool
        {
            return $this->async(mkdir(...), $path, $mode, ($options & STREAM_MKDIR_RECURSIVE) === STREAM_MKDIR_RECURSIVE);
        }

        public function rename(string $from, string $to): bool
        {
            return $this->async(rename(...), $from, $to);
        }

        public function rmdir(string $path): bool
        {
            return $this->async(rename(...), $path);
        }

        public function stream_open(
            string $path,
            string $mode,
            int $options,
            ?string &$opened_path,
        ): bool
        {
            $path = substr($path, 8);
            $this->resource = $this->async(fopen(...), $path, $mode);

            $this->reportErrors = ($options & STREAM_REPORT_ERRORS) === STREAM_REPORT_ERRORS;
            if (!$this->resource) {
                if ($this->reportErrors) {
                    trigger_error("Unable to open stream {$path}", E_USER_ERROR);
                }

                return false;
            }


            if (($options & STREAM_USE_PATH) === STREAM_USE_PATH) {
                $opened_path = $path;
            }

            return $this->resource !== false;
        }

        public function stream_cast(int $as): mixed
        {
            return $this->resource ?
                $this->resource : false;
        }

        public function stream_close()
        {
            $this->async(fclose(...), $this->resource);
            $this->resource = false;
        }

        public function stream_eof(): bool
        {
            return $this->async(feof(...), $this->resource);
        }

        public function stream_flush(): bool
        {
            return $this->async(fflush(...), $this->resource);
        }

        public function stream_lock(int $operation): bool
        {
            if ($operation === 0) {
                return true;
            }

            return $this->async(flock(...), $this->resource, $operation);
        }

        public function stream_metadata(string $path, int $option, mixed $value): bool
        {
            return match ($option) {
                STREAM_META_TOUCH => empty($value) ? $this->async(touch(...), $path, $value[0] ?? null, $value[1] ?? null) : $this->async(touch(...), $path),
                STREAM_META_OWNER => $this->async(chown(...), $path, $value),
                STREAM_META_OWNER_NAME => $this->async(chown(...), $path, $value),
                STREAM_META_GROUP => $this->async(chgrp(...), $path, $value),
                STREAM_META_ACCESS => $this->async(chmod(...), $path, $value),
                default => false,
            };
        }

        public function stream_read(int $count): string|false
        {
            return $this->async(fread(...), $this->resource, $count);
        }

        public function stream_seek(int $offset, int $whence = SEEK_SET): bool
        {
            return $this->async(fseek(...), $this->resource, $offset, $whence) === 0;
        }

        public function stream_set_option(int $option, ?int $arg1 = null, ?int $arg2 = null): bool
        {
            return match ($option) {
                STREAM_OPTION_BLOCKING => $this->async(stream_set_blocking(...), $this->resource, $arg1),
                STREAM_OPTION_READ_TIMEOUT => $this->async(stream_set_timeout(...), $this->resource, $arg1, $arg2),
                STREAM_OPTION_WRITE_BUFFER => $this->async(stream_set_write_buffer(...), $this->resource, $arg2) === 0,
                STREAM_OPTION_READ_BUFFER => $this->async(stream_set_read_buffer(...), $this->resource, $arg2) === 0,
            };
        }

        public function stream_stat(): array |false
        {
            return $this->async(fstat(...), $this->resource);
        }

        public function stream_tell(): int
        {
            return $this->async(ftell(...), $this->resource);
        }

        public function stream_truncate(int $size): bool
        {
            return $this->async(ftruncate(...), $this->resource, $size);
        }

        public function stream_write(string $data): int
        {
            return $this->async(fwrite(...), $this->resource, $data);
        }

        public function unlink(string $path): bool
        {
            return $this->async(unlink(...), $path);
        }

        public function url_stat(string $path, int $flags): array |false
        {
            return (($flags & STREAM_URL_STAT_LINK) === STREAM_URL_STAT_LINK) ?
                $this->async(lstat(...), $path) :
                $this->async(stat(...), $path);
        }
    }
    AsyncStreamWrapper::register();
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
