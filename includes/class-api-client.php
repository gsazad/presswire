<?php

if (!defined('ABSPATH')) {
    exit;
}

class Presswire_API_Client {

    private $api_key;
    private $provider_id;
    private $endpoint;
    private $last_error = '';
    private $last_meta = [];

    public function __construct() {
        $this->api_key = (string) get_option('presswire_api_key');
        $this->provider_id = (string) get_option('presswire_provider_id');
        $this->endpoint = (string) get_option('presswire_api_endpoint', PRESSWIRE_IMPORTER_DEFAULT_ENDPOINT);
    }

    public function fetch_releases($params = [], $bypass_cache = false) {
        $this->last_error = '';
        $this->last_meta = [];

        if (!$this->is_configured()) {
            $this->last_error = 'Presswire API settings are incomplete.';
            return [];
        }

        $params = $this->normalize_params($params);
        $cache_key = 'presswire_releases_' . md5(wp_json_encode([$this->provider_id, $params]));

        if (!$bypass_cache) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return is_array($cached) ? $cached : [];
            }
        }

        $response = $this->request($this->endpoint, $params);

        if ($response === null) {
            return [];
        }

        $this->last_meta = !empty($response['meta']) && is_array($response['meta']) ? $response['meta'] : [];

        if (empty($response['items']) || !is_array($response['items'])) {
            if (!$bypass_cache) {
                set_transient($cache_key, [], 10 * MINUTE_IN_SECONDS);
            }

            return [];
        }

        $releases = array_values(
            array_filter(
                array_map([$this, 'normalize_release'], $response['items']),
                static function ($release) {
                    return !empty($release['release_key']);
                }
            )
        );

        if (!$bypass_cache) {
            set_transient($cache_key, $releases, 10 * MINUTE_IN_SECONDS);
        }

        return $releases;
    }

    public function fetch_release_by_number($release_number, $limit = 60, $max_pages = 10) {
        $params = [
            'limit' => max(1, min(60, (int) $limit)),
        ];
        $page = 0;

        do {
            $releases = $this->fetch_releases($params, true);

            if ($this->last_error !== '') {
                return null;
            }

            foreach ($releases as $release) {
                if (!empty($release['release_number']) && (int) $release['release_number'] === (int) $release_number) {
                    return $release;
                }
            }

            $page++;
            $next_cursor = !empty($this->last_meta['next_cursor']) ? (string) $this->last_meta['next_cursor'] : '';

            if ($next_cursor === '' || $page >= $max_pages) {
                break;
            }

            $params['cursor'] = $next_cursor;
        } while (true);

        return null;
    }

    public function fetch_release_by_key($release_key) {
        $this->last_error = '';
        $this->last_meta = [];

        if (!$this->is_configured()) {
            $this->last_error = 'Presswire API settings are incomplete.';
            return null;
        }

        $release_key = sanitize_text_field((string) $release_key);

        if ($release_key === '') {
            $this->last_error = 'Release key is missing.';
            return null;
        }

        $response = $this->request(trailingslashit($this->endpoint) . rawurlencode($release_key));

        if ($response === null) {
            return null;
        }

        if (!is_array($response)) {
            return null;
        }

        $release = $this->normalize_release($response);

        return !empty($release['release_key']) ? $release : null;
    }

    public function fetch_categories($bypass_cache = false) {
        $this->last_error = '';
        $this->last_meta = [];

        if (!$this->is_configured()) {
            $this->last_error = 'Presswire API settings are incomplete.';
            return [];
        }

        $endpoint = $this->get_categories_endpoint();
        $cache_key = 'presswire_categories_' . md5(wp_json_encode([$this->provider_id, $endpoint]));

        if (!$bypass_cache) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return is_array($cached) ? $cached : [];
            }
        }

        $response = $this->request($endpoint);

        if ($response === null) {
            return [];
        }

        $items = [];

        if (!empty($response['items']) && is_array($response['items'])) {
            $items = $response['items'];
        } elseif (!empty($response['categories']) && is_array($response['categories'])) {
            $items = $response['categories'];
        } elseif (array_is_list($response)) {
            $items = $response;
        }

        $categories = array_values(
            array_filter(
                array_map([$this, 'normalize_category'], $items),
                static function ($category) {
                    return !empty($category['remote_key']);
                }
            )
        );

        if (!$bypass_cache) {
            set_transient($cache_key, $categories, HOUR_IN_SECONDS);
        }

        return $categories;
    }

    public function get_last_error() {
        return $this->last_error;
    }

    public function get_last_meta() {
        return $this->last_meta;
    }

    private function is_configured() {
        return $this->api_key !== '' && $this->provider_id !== '' && $this->endpoint !== '';
    }

    private function request($url, $params = []) {
        $request_url = empty($params) ? $url : add_query_arg($params, $url);

        $response = wp_remote_get(
            $request_url,
            [
                'headers' => [
                    'X-Provider-Id' => $this->provider_id,
                    'Authorization' => 'ApiKey ' . $this->api_key,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($status_code !== 200) {
            $data = json_decode($body, true);
            $this->last_error = is_array($data) && !empty($data['error']['message'])
                ? (string) $data['error']['message']
                : sprintf('Presswire API request failed with status %d and returned non-JSON content.', $status_code);
            return null;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            $this->last_error = 'Presswire API returned a 200 response but not valid JSON.';
            return null;
        }

        return $data;
    }

    private function normalize_params($params) {
        $normalized = [];

        if (isset($params['category_id'])) {
            $normalized['category_id'] = absint($params['category_id']);
        }

        if (isset($params['q'])) {
            $normalized['q'] = sanitize_text_field((string) $params['q']);
        }

        if (isset($params['limit'])) {
            $normalized['limit'] = max(1, min(60, (int) $params['limit']));
        }

        if (isset($params['since']) && $params['since'] !== '') {
            $normalized['since'] = sanitize_text_field((string) $params['since']);
        }

        if (isset($params['cursor']) && $params['cursor'] !== '') {
            $normalized['cursor'] = sanitize_text_field((string) $params['cursor']);
        }

        if (empty($normalized['limit'])) {
            $normalized['limit'] = 10;
        }

        return $normalized;
    }

    private function get_categories_endpoint() {
        return preg_replace('#/releases/?$#', '/categories', $this->endpoint);
    }

    private function normalize_release($release) {
        if (!is_array($release)) {
            return [];
        }

        if (!empty($release['release_key'])) {
            return $release;
        }

        $section = [];

        if (!empty($release['section']) && is_array($release['section'])) {
            $section = [
                'section_code' => $release['section']['section_code'] ?? null,
                'section_title' => $release['section']['section_title'] ?? '',
                'section_handle' => $release['section']['section_handle'] ?? '',
            ];
        } elseif (!empty($release['category']) && is_array($release['category'])) {
            $section = [
                'section_code' => $release['category']['id'] ?? null,
                'section_title' => $release['category']['name'] ?? '',
                'section_handle' => $release['category']['slug'] ?? '',
            ];
        }

        return [
            'release_number' => $release['release_number'] ?? $release['id'] ?? 0,
            'release_key' => $release['release_key'] ?? $release['uuid'] ?? '',
            'story_handle' => $release['story_handle'] ?? $release['slug'] ?? '',
            'headline' => $release['headline'] ?? $release['title'] ?? '',
            'quick_summary' => $release['quick_summary'] ?? $release['summary'] ?? '',
            'full_story' => $release['full_story'] ?? $release['body'] ?? '',
            'source_name' => $release['source_name'] ?? $release['organization'] ?? '',
            'content_language' => $release['content_language'] ?? $release['language'] ?? '',
            'section' => $section,
            'topic_tags' => $release['topic_tags'] ?? $release['tags'] ?? [],
            'dateline' => $release['dateline'] ?? $release['location'] ?? '',
            'cover_asset' => $release['cover_asset'] ?? $release['image'] ?? '',
            'live_date' => $release['live_date'] ?? $release['published_at'] ?? '',
            'revised_date' => $release['revised_date'] ?? $release['updated_at'] ?? '',
            'story_link' => $release['story_link'] ?? $release['canonical_url'] ?? '',
            'publisher_notice' => $release['publisher_notice'] ?? $release['disclaimer'] ?? '',
        ];
    }

    private function normalize_category($category) {
        if (!is_array($category)) {
            return [];
        }

        $code = $category['section_code'] ?? $category['id'] ?? '';
        $title = $category['section_title'] ?? $category['name'] ?? '';
        $handle = $category['section_handle'] ?? $category['slug'] ?? '';

        if ($code !== '' && $code !== null) {
            $remote_key = (string) $code;
        } elseif ($handle !== '') {
            $remote_key = (string) $handle;
        } else {
            $remote_key = (string) $title;
        }

        return [
            'remote_key' => sanitize_text_field($remote_key),
            'section_code' => is_scalar($code) ? (string) $code : '',
            'section_title' => is_scalar($title) ? (string) $title : '',
            'section_handle' => is_scalar($handle) ? (string) $handle : '',
        ];
    }
}
