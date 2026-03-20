<?php

namespace ImageOptimizer;

use Composer\Script\Event;

class DependencyChecker
{
    public static function check(Event $event): void
    {
        $io = $event->getIO();

        if (!extension_loaded('imagick')) {
            $io->writeError('');
            $io->writeError('<warning>⚠  image-optimizer requires the PHP Imagick extension.</warning>');
            $io->writeError('<warning>   Run: sudo apt install -y php-imagick</warning>');
            $io->writeError('');
        } else {
            $io->write('<info>✓  image-optimizer: PHP Imagick extension detected.</info>');
        }
    }
}
