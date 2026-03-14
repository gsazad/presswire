<?php

if (!defined('ABSPATH')) {
    exit;
}

class Presswire_Field_Mapper {

    const OPTION_NAME = 'presswire_field_mapping';

    private $mapping;

    public function __construct() {
        $this->mapping = self::normalize_mapping(get_option(self::OPTION_NAME, []));
    }

    public static function get_available_source_fields() {
        return [
            '' => 'Do not map',
            'release_number' => 'release_number',
            'release_key' => 'release_key',
            'story_handle' => 'story_handle',
            'headline' => 'headline',
            'quick_summary' => 'quick_summary',
            'full_story' => 'full_story',
            'source_name' => 'source_name',
            'content_language' => 'content_language',
            'section.section_code' => 'section.section_code',
            'section.section_title' => 'section.section_title',
            'section.section_handle' => 'section.section_handle',
            'topic_tags' => 'topic_tags',
            'dateline' => 'dateline',
            'cover_asset' => 'cover_asset',
            'live_date' => 'live_date',
            'revised_date' => 'revised_date',
            'story_link' => 'story_link',
            'publisher_notice' => 'publisher_notice',
        ];
    }

    public static function get_default_mapping() {
        return [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_fields' => [
                'post_title' => 'headline',
                'post_content' => 'full_story',
                'post_excerpt' => 'quick_summary',
                'post_name' => 'story_handle',
                'post_date' => 'live_date',
            ],
            'taxonomies' => [
                'category' => 'section.section_title',
                'post_tag' => 'topic_tags',
            ],
            'meta_fields' => [
                [
                    'source' => 'release_key',
                    'meta_key' => 'presswire_release_key',
                ],
                [
                    'source' => 'release_number',
                    'meta_key' => 'presswire_release_number',
                ],
                [
                    'source' => 'source_name',
                    'meta_key' => 'presswire_source_name',
                ],
                [
                    'source' => 'story_link',
                    'meta_key' => 'presswire_story_link',
                ],
                [
                    'source' => 'dateline',
                    'meta_key' => 'presswire_dateline',
                ],
                [
                    'source' => 'publisher_notice',
                    'meta_key' => 'presswire_publisher_notice',
                ],
                [
                    'source' => 'content_language',
                    'meta_key' => 'presswire_content_language',
                ],
                [
                    'source' => 'revised_date',
                    'meta_key' => 'presswire_revised_date',
                ],
            ],
        ];
    }

    public static function normalize_mapping($mapping) {
        $defaults = self::get_default_mapping();
        $sources = self::get_available_source_fields();
        $normalized = [
            'post_type' => sanitize_key($defaults['post_type']),
            'post_status' => sanitize_key($defaults['post_status']),
            'post_fields' => $defaults['post_fields'],
            'taxonomies' => $defaults['taxonomies'],
            'meta_fields' => $defaults['meta_fields'],
        ];

        if (!is_array($mapping)) {
            return $normalized;
        }

        if (!empty($mapping['post_type'])) {
            $normalized['post_type'] = sanitize_key($mapping['post_type']);
        }

        if (!empty($mapping['post_status'])) {
            $normalized['post_status'] = sanitize_key($mapping['post_status']);
        }

        if (!empty($mapping['post_fields']) && is_array($mapping['post_fields'])) {
            foreach ($defaults['post_fields'] as $target => $default_source) {
                $source = isset($mapping['post_fields'][$target]) ? (string) $mapping['post_fields'][$target] : $default_source;
                $normalized['post_fields'][$target] = isset($sources[$source]) ? $source : $default_source;
            }
        }

        if (!empty($mapping['taxonomies']) && is_array($mapping['taxonomies'])) {
            foreach ($defaults['taxonomies'] as $taxonomy => $default_source) {
                $source = isset($mapping['taxonomies'][$taxonomy]) ? (string) $mapping['taxonomies'][$taxonomy] : $default_source;
                $normalized['taxonomies'][$taxonomy] = isset($sources[$source]) ? $source : $default_source;
            }
        }

        $normalized['meta_fields'] = [];

        if (!empty($mapping['meta_fields']) && is_array($mapping['meta_fields'])) {
            foreach ($mapping['meta_fields'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $source = isset($row['source']) ? (string) $row['source'] : '';
                $meta_key = isset($row['meta_key']) ? sanitize_key($row['meta_key']) : '';

                if ($source === '' || $meta_key === '' || !isset($sources[$source])) {
                    continue;
                }

                $normalized['meta_fields'][] = [
                    'source' => $source,
                    'meta_key' => $meta_key,
                ];
            }
        }

        if (empty($normalized['meta_fields'])) {
            $normalized['meta_fields'] = $defaults['meta_fields'];
        }

        return $normalized;
    }

    public function get_mapping() {
        return $this->mapping;
    }

    public function map_fields($release) {
        $post_data = [
            'post_status' => $this->mapping['post_status'],
            'post_type' => $this->mapping['post_type'],
        ];

        foreach ($this->mapping['post_fields'] as $target => $source) {
            if ($source === '') {
                continue;
            }

            $value = $this->get_release_value($release, $source);

            if ($value === null || $value === '') {
                continue;
            }

            switch ($target) {
                case 'post_title':
                    $post_data['post_title'] = sanitize_text_field($this->stringify_value($value, false));
                    break;

                case 'post_content':
                    $post_data['post_content'] = wp_kses_post($this->stringify_value($value, true));
                    break;

                case 'post_excerpt':
                    $post_data['post_excerpt'] = sanitize_textarea_field($this->stringify_value($value, false));
                    break;

                case 'post_name':
                    $post_data['post_name'] = sanitize_title($this->stringify_value($value, false));
                    break;

                case 'post_date':
                    $timestamp = strtotime($this->stringify_value($value, false));

                    if ($timestamp !== false) {
                        $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
                        $post_data['post_date'] = get_date_from_gmt($post_data['post_date_gmt']);
                    }
                    break;
            }
        }

        return $post_data;
    }

    public function map_taxonomies($release) {
        $mapped = [];

        foreach ($this->mapping['taxonomies'] as $taxonomy => $source) {
            if ($source === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $value = $this->get_release_value($release, $source);
            $terms = $this->normalize_terms($value);

            if (!empty($terms)) {
                $mapped[$taxonomy] = $terms;
            }
        }

        return $mapped;
    }

    public function map_meta_fields($release) {
        $mapped = [];

        foreach ($this->mapping['meta_fields'] as $row) {
            if (empty($row['source']) || empty($row['meta_key'])) {
                continue;
            }

            $value = $this->get_release_value($release, $row['source']);

            if ($value === null || $value === '') {
                continue;
            }

            $mapped[$row['meta_key']] = $this->normalize_meta_value($value);
        }

        return $mapped;
    }

    private function get_release_value($release, $source) {
        if (!is_array($release) || $source === '') {
            return null;
        }

        $segments = explode('.', $source);
        $value = $release;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function normalize_terms($value) {
        if (is_array($value)) {
            $terms = [];

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $term = sanitize_text_field(ltrim((string) $item, '#'));

                    if ($term !== '') {
                        $terms[] = $term;
                    }
                }
            }

            return array_values(array_unique($terms));
        }

        if (is_scalar($value)) {
            $term = sanitize_text_field((string) $value);
            return $term === '' ? [] : [$term];
        }

        return [];
    }

    private function normalize_meta_value($value) {
        if (is_array($value)) {
            $scalar_values = [];

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $scalar_values[] = sanitize_text_field((string) $item);
                }
            }

            if (!empty($scalar_values) && count($scalar_values) === count($value)) {
                return implode(', ', $scalar_values);
            }

            return wp_json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return sanitize_textarea_field((string) $value);
        }

        return '';
    }

    private function stringify_value($value, $allow_html) {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            }

            return implode(', ', $parts);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return $allow_html ? (string) $value : trim((string) $value);
    }
}
