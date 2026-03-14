<?php

if (!defined('ABSPATH')) {
    exit;
}

class Presswire_Scheduler {

    public function __construct() {
        add_action('init', [__CLASS__, 'maybe_schedule_event']);
        add_action('presswire_import_event', [$this, 'trigger_import']);
    }

    public static function maybe_schedule_event() {
        if (!wp_next_scheduled('presswire_import_event')) {
            self::schedule_event();
        }
    }

    public static function schedule_event() {
        if (!wp_next_scheduled('presswire_import_event')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'presswire_import_event');
        }
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook('presswire_import_event');
    }

    public function trigger_import() {
        do_action('presswire_run_import');
    }
}
