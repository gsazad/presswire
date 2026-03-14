<?php
/*
Plugin Name: Presswire Importer
Description: Import press releases from PresswireIndia API.
Version: 1.3
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

define('PRESSWIRE_IMPORTER_PATH', plugin_dir_path(__FILE__));
define('PRESSWIRE_IMPORTER_URL', plugin_dir_url(__FILE__));
define('PRESSWIRE_IMPORTER_DEFAULT_ENDPOINT', 'https://presswireindia.com/api/v1/news/releases');

require_once PRESSWIRE_IMPORTER_PATH . 'includes/class-api-client.php';
require_once PRESSWIRE_IMPORTER_PATH . 'includes/class-importer.php';
require_once PRESSWIRE_IMPORTER_PATH . 'includes/class-field-mapper.php';
require_once PRESSWIRE_IMPORTER_PATH . 'includes/class-admin-settings.php';
require_once PRESSWIRE_IMPORTER_PATH . 'includes/class-scheduler.php';

class PresswireImporter {

    public function __construct() {
        new Presswire_Admin_Settings();
        new Presswire_Scheduler();

        add_action('presswire_run_import', [$this, 'run_import']);
        add_action('admin_post_presswire_import_release', [$this, 'import_single_release']);
    }

    public static function activate() {
        if (!get_option('presswire_api_endpoint')) {
            update_option('presswire_api_endpoint', PRESSWIRE_IMPORTER_DEFAULT_ENDPOINT);
        }

        Presswire_Scheduler::schedule_event();
    }

    public static function deactivate() {
        Presswire_Scheduler::clear_schedule();
    }

    public function run_import() {
        $client = new Presswire_API_Client();
        $mapper = new Presswire_Field_Mapper();
        $importer = new Presswire_Importer($mapper);

        $params = [
            'limit' => 60,
        ];

        $since = get_option('presswire_last_sync');
        $latest_timestamp = $since ?: '';
        $stats = [
            'imported' => 0,
            'duplicates' => 0,
            'errors' => 0,
        ];

        if (!empty($since)) {
            $params['since'] = $since;
        }

        do {
            $releases = $client->fetch_releases($params, true);

            if ($client->get_last_error()) {
                update_option('presswire_last_error', $client->get_last_error());
                return $stats;
            }

            foreach ($releases as $release) {
                $result = $importer->import_release($release);

                if (isset($stats[$result['status']])) {
                    $stats[$result['status']]++;
                }

                $release_timestamp = $this->get_release_timestamp($release);
                if ($this->is_newer_timestamp($release_timestamp, $latest_timestamp)) {
                    $latest_timestamp = $release_timestamp;
                }
            }

            $meta = $client->get_last_meta();
            $next_cursor = isset($meta['next_cursor']) ? (string) $meta['next_cursor'] : '';

            if ($next_cursor === '') {
                break;
            }

            $params['cursor'] = $next_cursor;
        } while (true);

        update_option('presswire_last_error', '');

        if (!empty($latest_timestamp) && $latest_timestamp !== $since) {
            update_option('presswire_last_sync', $latest_timestamp);
        }

        return $stats;
    }

    public function import_single_release() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $mode = isset($_GET['mode']) ? sanitize_key(wp_unslash($_GET['mode'])) : 'import';
        $release_key = isset($_GET['release_key']) ? sanitize_text_field(wp_unslash($_GET['release_key'])) : '';

        if ($release_key === '') {
            $this->redirect_to_admin('error', 'Invalid release key.');
        }

        check_admin_referer('presswire_import_release_' . $release_key);

        $client = new Presswire_API_Client();
        $release = $client->fetch_release_by_key($release_key);

        if ($client->get_last_error()) {
            $this->redirect_to_admin('error', $client->get_last_error());
        }

        if (!$release) {
            $this->redirect_to_admin('error', 'Release not found in the API feed.');
        }

        $mapper = new Presswire_Field_Mapper();
        $importer = new Presswire_Importer($mapper);
        $result = $importer->import_release(
            $release,
            [
                'allow_update' => $mode === 'update',
            ]
        );

        switch ($result['status']) {
            case 'imported':
                $this->redirect_to_admin('success', 'Release imported successfully.');
                break;

            case 'updated':
                $this->redirect_to_admin('success', 'Release updated successfully.');
                break;

            case 'duplicate':
                $this->redirect_to_admin('warning', 'This release has already been imported.');
                break;

            default:
                $message = !empty($result['message']) ? $result['message'] : 'Import failed.';
                $this->redirect_to_admin('error', $message);
        }
    }

    private function get_release_timestamp($release) {
        if (!empty($release['revised_date'])) {
            return (string) $release['revised_date'];
        }

        if (!empty($release['live_date'])) {
            return (string) $release['live_date'];
        }

        return '';
    }

    private function is_newer_timestamp($candidate, $current) {
        if ($candidate === '') {
            return false;
        }

        if ($current === '') {
            return true;
        }

        $candidate_time = strtotime($candidate);
        $current_time = strtotime($current);

        if ($candidate_time === false) {
            return false;
        }

        if ($current_time === false) {
            return true;
        }

        return $candidate_time > $current_time;
    }

    private function redirect_to_admin($status, $message) {
        $url = add_query_arg(
            [
                'page' => 'presswire-importer',
                'presswire_notice' => sanitize_key($status),
                'presswire_message' => rawurlencode($message),
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}

register_activation_hook(__FILE__, ['PresswireImporter', 'activate']);
register_deactivation_hook(__FILE__, ['PresswireImporter', 'deactivate']);

new PresswireImporter();
