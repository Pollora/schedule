<?php

declare(strict_types=1);

namespace {

    /**
     * What phpstan needs to know of WP-CLI, which is not a dependency.
     */
    class WP_CLI
    {
        public static function add_command(string $name, mixed $callable, array $args = []): bool
        {
            return true;
        }

        public static function log(string $message): void {}

        public static function success(string $message): void {}

        public static function warning(string $message): void {}

        /**
         * @return never
         */
        public static function error(string $message): void
        {
            exit(1);
        }
    }

    class WP_CLI_Command {}

    class Cron_Event_Command extends WP_CLI_Command
    {
        /**
         * @return array<int, stdClass>|WP_Error
         */
        protected static function get_cron_events(bool $is_due_now = false): array|WP_Error
        {
            return [];
        }

        protected static function get_hook_actions(string $hook_name): string
        {
            return '';
        }

        protected static function run_event(stdClass $event)
        {
            return true;
        }
    }
}

namespace WP_CLI {
    class Formatter
    {
        public string $format = 'table';

        /**
         * @var list<string>
         */
        public array $fields = [];

        /**
         * @param  array<string, mixed>  $assoc_args
         * @param  list<string>  $fields
         */
        public function __construct(array &$assoc_args, array $fields, string $prefix) {}

        /**
         * @param  iterable<mixed>  $items
         */
        public function display_items(iterable $items): void {}
    }
}
