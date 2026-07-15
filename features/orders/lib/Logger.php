<?php
namespace Prospektweb\LayoutFiles;

class Logger
{
    public static function error(string $stage, \Throwable $exception, array $context = []): void
    {
        $message = '[' . $stage . '] ' . $exception->getMessage();
        if ($context) {
            $message .= ' | ' . print_r($context, true);
        }
        if (function_exists('AddMessage2Log')) {
            AddMessage2Log($message, Config::MODULE_ID);
        }
    }

    public static function message(string $stage, string $message, array $context = []): void
    {
        if ($context) {
            $message .= ' | ' . print_r($context, true);
        }
        if (function_exists('AddMessage2Log')) {
            AddMessage2Log('[' . $stage . '] ' . $message, Config::MODULE_ID);
        }
    }
}
