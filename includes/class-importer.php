<?php

if (!defined('ABSPATH')) {
    exit;
}

class Presswire_Importer {

    private $mapper;

    public function __construct($mapper) {
        $this->mapper = $mapper;
    }

    public static function get_existing_post_id_by_release_key($release_key) {
        $release_key = sanitize_text_field((string) $release_key);

        if ($release_key === '') {
            return 0;
        }

        $existing = get_posts(
            [
                'meta_key' => 'presswire_release_key',
                'meta_value' => $release_key,
                'post_type' => 'any',
                'numberposts' => 1,
                'post_status' => 'any',
                'fields' => 'ids',
            ]
        );

        return !empty($existing[0]) ? (int) $existing[0] : 0;
    }

    public function import_release($release, $args = []) {
        $args = wp_parse_args(
            $args,
            [
                'allow_update' => false,
            ]
        );

        if (empty($release) || !is_array($release)) {
            return [
                'status' => 'errors',
                'message' => 'Empty release payload.',
            ];
        }

        $release_key = !empty($release['release_key']) ? (string) $release['release_key'] : '';

        if ($release_key === '') {
            return [
                'status' => 'errors',
                'message' => 'Release key is missing.',
            ];
        }

        $existing_post_id = self::get_existing_post_id_by_release_key($release_key);

        if ($existing_post_id > 0 && !$args['allow_update']) {
            return [
                'status' => 'duplicate',
                'post_id' => $existing_post_id,
            ];
        }

        $post_data = $this->mapper->map_fields($release);

        if ($existing_post_id > 0 && $args['allow_update']) {
            $post_data['ID'] = $existing_post_id;
        }

        $post_id = $existing_post_id > 0 && $args['allow_update']
            ? wp_update_post(wp_slash($post_data), true)
            : wp_insert_post(wp_slash($post_data), true);

        if (is_wp_error($post_id)) {
            return [
                'status' => 'errors',
                'message' => $post_id->get_error_message(),
            ];
        }

        update_post_meta($post_id, 'presswire_release_key', $release_key);
        update_post_meta($post_id, 'presswire_release_number', isset($release['release_number']) ? absint($release['release_number']) : 0);

        foreach ($this->mapper->map_meta_fields($release) as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }

        foreach ($this->mapper->map_taxonomies($release) as $taxonomy => $terms) {
            wp_set_post_terms($post_id, $terms, $taxonomy);
        }

        $mapped_category_id = $this->get_mapped_category_id($release);
        if ($mapped_category_id > 0) {
            wp_set_post_terms($post_id, [$mapped_category_id], 'category');
        }

        if (!empty($release['cover_asset']) && filter_var($release['cover_asset'], FILTER_VALIDATE_URL)) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_sideload_image(
                esc_url_raw($release['cover_asset']),
                $post_id,
                null,
                'id'
            );

            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        return [
            'status' => $existing_post_id > 0 && $args['allow_update'] ? 'updated' : 'imported',
            'post_id' => (int) $post_id,
        ];
    }

    private function get_mapped_category_id($release) {
        if (empty($release['section']) || !is_array($release['section'])) {
            return 0;
        }

        $remote_key = '';

        if (isset($release['section']['section_code']) && $release['section']['section_code'] !== '') {
            $remote_key = (string) $release['section']['section_code'];
        } elseif (!empty($release['section']['section_handle'])) {
            $remote_key = (string) $release['section']['section_handle'];
        } elseif (!empty($release['section']['section_title'])) {
            $remote_key = (string) $release['section']['section_title'];
        }

        if ($remote_key === '') {
            return 0;
        }

        $mappings = get_option('presswire_category_mapping', []);

        if (!is_array($mappings) || empty($mappings[$remote_key])) {
            return 0;
        }

        $term_id = absint($mappings[$remote_key]);

        return $term_id > 0 ? $term_id : 0;
    }
}
