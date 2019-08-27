<?php

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Timer;

use function Onion\Framework\Loop\scheduler;

require __DIR__ . '/../vendor/autoload.php';

$config = [
    'dir' => __DIR__ . '/..',
    'extensions' => [
        'php',
    ],
    'reload-delay' => 250,
];

scheduler()->add(new Coroutine(function (array $config) {
    $registry = [];
    $delay = null;
    yield Timer::interval(function (array $config) use (&$registry, &$delay) {
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $config['dir'],
            RecursiveDirectoryIterator::FOLLOW_SYMLINKS |
            RecursiveDirectoryIterator::SKIP_DOTS |
            RecursiveDirectoryIterator::UNIX_PATHS
        ));

        foreach ($directory as $item) {
            if ($item->isDir() || !in_array($item->getExtension(), $config['extensions'])) {
                continue;
            }

            if (isset($registry[$item->getRealPath()][0]) && $registry[$item->getRealPath()][0] !== $item->getMTime()) {
                if ($registry[$item->getRealPath()][1] !== md5_file($item->getRealPath())) {
                    if ($delay) {
                        yield Coroutine::kill($delay);
                    }


                    $delay = yield Timer::after(function (\SplFileInfo $item) {
                        echo "Change in {$item->getRealPath()} detected, do something!\n";
                        yield;
                    }, $config['reload-delay'] ?? 3000, [$item]);

                }
            }

            $registry[$item->getRealPath()] = [$item->getMTime(), md5_file($item->getRealPath())];

            yield;
        }

        usleep(150000);
    }, 100, [$config]);
}, [$config]));

scheduler()->start();
