<?php

declare(strict_types=1);

namespace {
    /**
     * WP-CLI as the commands use it: it records what they say. `error()` stops the command, as the real one exits.
     */
    if (! class_exists('WP_CLI', false)) {
        final class WP_CLI
        {
            /** @var array<string, string> */
            public static array $commands = [];

            /** @var list<string> */
            public static array $logs = [];

            /** @var list<string> */
            public static array $warnings = [];

            /** @var list<string> */
            public static array $successes = [];

            public static function reset(): void
            {
                self::$commands = self::$logs = self::$warnings = self::$successes = [];
            }

            public static function add_command(string $name, string $callable): void
            {
                self::$commands[$name] = $callable;
            }

            public static function log(string $message): void
            {
                self::$logs[] = $message;
            }

            public static function warning(string $message): void
            {
                self::$warnings[] = $message;
            }

            public static function success(string $message): void
            {
                self::$successes[] = $message;
            }

            public static function error(string $message): never
            {
                throw new RuntimeException($message);
            }
        }
    }

    if (! class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(public string $code = '', public string $message = '') {}

            public function get_error_code(): string
            {
                return $this->code;
            }
        }
    }

    /**
     * The command WP-CLI bundles, reduced to what the one of the package relies on.
     */
    if (! class_exists('Cron_Event_Command', false)) {
        class Cron_Event_Command
        {
            /** @var list<stdClass>|mixed */
            public static mixed $events = [];

            /** @var array<string, list<string>> */
            public static array $actions = [];

            protected static function get_cron_events(): mixed
            {
                return self::$events;
            }

            /**
             * @return list<string>
             */
            protected static function get_hook_actions(string $hook): array
            {
                return self::$actions[$hook] ?? [];
            }
        }
    }
}

namespace WP_CLI {
    if (! class_exists(Formatter::class, false)) {
        final class Formatter
        {
            /** @var list<array{0: list<stdClass>, 1: list<string>}> */
            public static array $displayed = [];

            /** @var list<string> */
            public array $fields;

            public string $format;

            /**
             * @param  array<string, mixed>  $assoc_args
             * @param  list<string>  $fields
             */
            public function __construct(array $assoc_args, array $fields, string $prefix)
            {
                $this->fields = isset($assoc_args['fields']) ? explode(',', (string) $assoc_args['fields']) : $fields;
                $this->format = (string) ($assoc_args['format'] ?? 'table');
            }

            /**
             * @param  iterable<stdClass>  $items
             */
            public function display_items(iterable $items): void
            {
                self::$displayed[] = [array_values(is_array($items) ? $items : iterator_to_array($items)), $this->fields];
            }
        }
    }
}
