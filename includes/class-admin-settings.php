<?php

if (!defined('ABSPATH')) {
    exit;
}

class Presswire_Admin_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_head', [$this, 'print_admin_styles']);
    }

    public function menu() {
        add_menu_page(
            'Presswire Importer',
            'Presswire Importer',
            'manage_options',
            'presswire-importer',
            [$this, 'releases_page'],
            'dashicons-rss',
            25
        );

        add_submenu_page(
            'presswire-importer',
            'Releases',
            'Releases',
            'manage_options',
            'presswire-importer',
            [$this, 'releases_page']
        );

        add_submenu_page(
            'presswire-importer',
            'Settings',
            'Settings',
            'manage_options',
            'presswire-importer-settings',
            [$this, 'settings_page']
        );

        add_submenu_page(
            'presswire-importer',
            'Category Mapper',
            'Category Mapper',
            'manage_options',
            'presswire-importer-category-mapper',
            [$this, 'category_mapper_page']
        );
    }

    public function settings() {
        register_setting(
            'presswire_settings',
            'presswire_api_key',
            [
                'sanitize_callback' => [$this, 'sanitize_api_key'],
            ]
        );

        register_setting(
            'presswire_settings',
            'presswire_api_endpoint',
            [
                'sanitize_callback' => 'esc_url_raw',
                'default' => PRESSWIRE_IMPORTER_DEFAULT_ENDPOINT,
            ]
        );

        register_setting(
            'presswire_settings',
            'presswire_provider_id',
            [
                'sanitize_callback' => 'sanitize_text_field',
            ]
        );

        register_setting(
            'presswire_settings',
            Presswire_Field_Mapper::OPTION_NAME,
            [
                'sanitize_callback' => ['Presswire_Field_Mapper', 'normalize_mapping'],
                'default' => Presswire_Field_Mapper::get_default_mapping(),
            ]
        );

        register_setting(
            'presswire_category_mapping_settings',
            'presswire_category_mapping',
            [
                'sanitize_callback' => [$this, 'sanitize_category_mapping'],
                'default' => [],
            ]
        );
    }

    public function releases_page() {
        $client = new Presswire_API_Client();
        $releases = $client->fetch_releases(
            [
                'limit' => 10,
            ],
            true
        );
        $last_sync = get_option('presswire_last_sync');
        $configured = $this->has_api_configuration();
        $imported_count = 0;

        foreach ($releases as $release) {
            $release_key = !empty($release['release_key']) ? sanitize_text_field($release['release_key']) : '';

            if ($release_key !== '' && Presswire_Importer::get_existing_post_id_by_release_key($release_key) > 0) {
                $imported_count++;
            }
        }

        $this->render_page_start(
            'presswire-importer',
            'Live Feed',
            'Presswire Releases',
            'Review the latest releases, check sync readiness, and import or update items without leaving WordPress.',
            [
                [
                    'label' => 'Importer Settings',
                    'url' => admin_url('admin.php?page=presswire-importer-settings'),
                    'style' => 'secondary',
                ],
                [
                    'label' => 'Category Mapper',
                    'url' => admin_url('admin.php?page=presswire-importer-category-mapper'),
                    'style' => 'ghost',
                ],
            ],
            [
                [
                    'label' => 'Feed Status',
                    'value' => $configured ? 'Connected' : 'Needs setup',
                ],
                [
                    'label' => 'Imported in list',
                    'value' => (string) $imported_count,
                ],
            ]
        );

        $this->render_notice();
        $this->render_status_cards(
            [
                [
                    'label' => 'Feed Endpoint',
                    'value' => get_option('presswire_api_endpoint', PRESSWIRE_IMPORTER_DEFAULT_ENDPOINT),
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Provider ID',
                    'value' => get_option('presswire_provider_id') ?: 'Not configured',
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Last Sync',
                    'value' => $last_sync ?: 'Not synced yet',
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Visible Releases',
                    'value' => (string) count($releases),
                    'accent' => 'highlight',
                ],
            ]
        );

        echo '<div class="presswire-grid presswire-grid-2 presswire-grid-tight">';
        echo '<section class="presswire-card presswire-card-compact">';
        echo '<div class="presswire-card-head presswire-card-head-compact">';
        echo '<div><p class="presswire-card-kicker">Workflow</p><h2>Import queue</h2></div>';
        echo '</div>';
        echo '<ol class="presswire-checklist">';
        echo '<li>Review the latest feed items and confirm the target story.</li>';
        echo '<li>Use <strong>Import</strong> for new releases and <strong>Update</strong> when the release key already exists.</li>';
        echo '<li>Open the post editor directly from the table to verify mapped fields after import.</li>';
        echo '</ol>';
        echo '</section>';
        echo '<section class="presswire-card presswire-card-compact">';
        echo '<div class="presswire-card-head presswire-card-head-compact">';
        echo '<div><p class="presswire-card-kicker">Coverage</p><h2>Current feed snapshot</h2></div>';
        echo '</div>';
        echo '<dl class="presswire-definition-list">';
        echo '<div><dt>Available now</dt><dd>' . esc_html((string) count($releases)) . ' releases</dd></div>';
        echo '<div><dt>Ready to update</dt><dd>' . esc_html((string) $imported_count) . ' matched locally</dd></div>';
        echo '<div><dt>Ready to import</dt><dd>' . esc_html((string) max(count($releases) - $imported_count, 0)) . ' new items</dd></div>';
        echo '</dl>';
        echo '</section>';
        echo '</div>';

        if ($client->get_last_error()) {
            $this->render_callout('error', $client->get_last_error());
            $this->render_page_end();
            return;
        }

        if (empty($releases)) {
            $this->render_empty_state('No releases found in the current feed.');
            $this->render_page_end();
            return;
        }

        echo '<section class="presswire-card presswire-card-table">';
        echo '<div class="presswire-card-head">';
        echo '<div>';
        echo '<p class="presswire-card-kicker">Queue</p>';
        echo '<h2>Latest releases</h2>';
        echo '</div>';
        echo '<p class="presswire-card-note">Action buttons switch automatically from Import to Update when a release key already exists locally.</p>';
        echo '</div>';
        echo '<div class="presswire-table-wrap">';
        echo '<table class="wp-list-table widefat fixed striped presswire-table">';
        echo '<thead><tr>';
        echo '<th>Release</th>';
        echo '<th>Source</th>';
        echo '<th>Section</th>';
        echo '<th>Dateline</th>';
        echo '<th>Live Date</th>';
        echo '<th>Action</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($releases as $release) {
            $release_number = isset($release['release_number']) ? absint($release['release_number']) : 0;
            $release_key = !empty($release['release_key']) ? sanitize_text_field($release['release_key']) : '';
            $headline = !empty($release['headline']) ? $release['headline'] : '';
            $source_name = !empty($release['source_name']) ? $release['source_name'] : '';
            $section_title = !empty($release['section']['section_title']) ? $release['section']['section_title'] : '';
            $dateline = !empty($release['dateline']) ? $release['dateline'] : '';
            $live_date = !empty($release['live_date']) ? $release['live_date'] : '';
            $existing_post_id = Presswire_Importer::get_existing_post_id_by_release_key($release_key);
            $mode = $existing_post_id > 0 ? 'update' : 'import';
            $label = $existing_post_id > 0 ? 'Update' : 'Import';
            $state_label = $existing_post_id > 0 ? 'Mapped locally' : 'New release';
            $state_class = $existing_post_id > 0 ? 'success' : 'muted';

            $action_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'presswire_import_release',
                        'mode' => $mode,
                        'release_key' => $release_key,
                    ],
                    admin_url('admin-post.php')
                ),
                'presswire_import_release_' . $release_key
            );

            echo '<tr>';
            echo '<td>';
            echo '<div class="presswire-release-cell">';
            echo '<span class="presswire-badge is-' . esc_attr($state_class) . '">' . esc_html($state_label) . '</span>';
            echo '<strong>' . esc_html($headline) . '</strong>';
            echo '<div class="presswire-subline">Release #' . esc_html((string) $release_number) . '</div>';
            echo '<code class="presswire-key">' . esc_html($release_key) . '</code>';
            echo '</div>';
            echo '</td>';
            echo '<td><span class="presswire-chip">' . esc_html($source_name) . '</span></td>';
            echo '<td><span class="presswire-chip presswire-chip-muted">' . esc_html($section_title) . '</span></td>';
            echo '<td>' . esc_html($dateline) . '</td>';
            echo '<td>' . esc_html($live_date) . '</td>';
            echo '<td class="presswire-actions">';
            echo '<a class="button button-primary" href="' . esc_url($action_url) . '">' . esc_html($label) . '</a>';

            if ($existing_post_id > 0) {
                echo ' <a class="button button-secondary" href="' . esc_url(get_edit_post_link($existing_post_id)) . '">Edit Post</a>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</section>';

        $this->render_page_end();
    }

    public function settings_page() {
        $endpoint = get_option('presswire_api_endpoint', PRESSWIRE_IMPORTER_DEFAULT_ENDPOINT);
        $provider_id = get_option('presswire_provider_id');
        $api_key = get_option('presswire_api_key');
        $last_sync = get_option('presswire_last_sync');
        $last_error = get_option('presswire_last_error');
        $mapping = Presswire_Field_Mapper::normalize_mapping(get_option(Presswire_Field_Mapper::OPTION_NAME, []));
        $source_fields = Presswire_Field_Mapper::get_available_source_fields();
        $meta_mappings = !empty($mapping['meta_fields']) ? count($mapping['meta_fields']) : 0;

        $this->render_page_start(
            'presswire-importer-settings',
            'Configuration',
            'Importer Settings',
            'Control API access, target content rules, and field mapping defaults from one place.',
            [
                [
                    'label' => 'View Releases',
                    'url' => admin_url('admin.php?page=presswire-importer'),
                    'style' => 'secondary',
                ],
                [
                    'label' => 'Category Mapper',
                    'url' => admin_url('admin.php?page=presswire-importer-category-mapper'),
                    'style' => 'ghost',
                ],
            ],
            [
                [
                    'label' => 'Target type',
                    'value' => $mapping['post_type'],
                ],
                [
                    'label' => 'Meta fields',
                    'value' => (string) $meta_mappings,
                ],
            ]
        );

        if (!empty($last_error)) {
            $this->render_callout('error', $last_error);
        }

        $this->render_status_cards(
            [
                [
                    'label' => 'API Endpoint',
                    'value' => $endpoint,
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Provider ID',
                    'value' => $provider_id ?: 'Not configured',
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Target Post Type',
                    'value' => $mapping['post_type'],
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Saved Meta Rules',
                    'value' => (string) $meta_mappings,
                    'accent' => 'highlight',
                ],
            ]
        );

        echo '<form method="post" action="options.php">';
        settings_fields('presswire_settings');

        echo '<div class="presswire-grid presswire-grid-2">';
        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Access</p><h2>API Settings</h2></div>';
        echo '<p class="presswire-card-note">Stored credentials are reused across releases, updates, and category mapping.</p>';
        echo '</div>';
        echo '<p class="presswire-helper">Keep these values stable. The importer uses them for feed browsing, single-release updates, and category sync.</p>';
        echo '<table class="form-table presswire-form-table">';
        echo '<tr><th scope="row"><label for="presswire_api_endpoint">API Endpoint</label></th><td><input id="presswire_api_endpoint" type="url" name="presswire_api_endpoint" value="' . esc_attr($endpoint) . '" class="regular-text code"></td></tr>';
        echo '<tr><th scope="row"><label for="presswire_provider_id">Provider ID</label></th><td><input id="presswire_provider_id" type="text" name="presswire_provider_id" value="' . esc_attr($provider_id) . '" class="regular-text"></td></tr>';
        echo '<tr><th scope="row"><label for="presswire_api_key">API Key</label></th><td><input id="presswire_api_key" type="password" name="presswire_api_key" value="' . esc_attr($api_key) . '" class="regular-text" autocomplete="off"><p class="description">Leaving this blank preserves the current saved key.</p></td></tr>';
        echo '<tr><th scope="row">Last Sync</th><td><span class="presswire-chip">' . esc_html($last_sync ?: 'Not synced yet') . '</span></td></tr>';
        echo '</table>';
        echo '</section>';

        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Defaults</p><h2>Import Behavior</h2></div>';
        echo '<p class="presswire-card-note">Choose how incoming releases should be created inside WordPress.</p>';
        echo '</div>';
        echo '<ul class="presswire-mini-list">';
        echo '<li>Use a dedicated post type if releases should stay separate from blog posts.</li>';
        echo '<li>Start with <strong>Draft</strong> if an editor should review imported content before publishing.</li>';
        echo '</ul>';
        echo '<table class="form-table presswire-form-table">';
        echo '<tr><th scope="row"><label for="presswire_post_type">Target Post Type</label></th><td><select id="presswire_post_type" name="' . esc_attr(Presswire_Field_Mapper::OPTION_NAME) . '[post_type]">';
        foreach ($this->get_post_type_options() as $post_type => $label) {
            echo '<option value="' . esc_attr($post_type) . '"' . selected($mapping['post_type'], $post_type, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="presswire_post_status">Imported Post Status</label></th><td><select id="presswire_post_status" name="' . esc_attr(Presswire_Field_Mapper::OPTION_NAME) . '[post_status]">';
        foreach ($this->get_post_status_options() as $status => $label) {
            echo '<option value="' . esc_attr($status) . '"' . selected($mapping['post_status'], $status, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</table>';
        echo '</section>';
        echo '</div>';

        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Mapping</p><h2>Core Post Fields</h2></div>';
        echo '<p class="presswire-card-note">Decide which remote fields populate title, content, excerpt, slug, and publish date.</p>';
        echo '</div>';
        echo '<div class="presswire-inline-banner">';
        echo '<strong>Recommended baseline</strong>';
        echo '<span>Use <code>headline</code> for title, <code>full_story</code> for content, and <code>live_date</code> for publish date to mirror the source release cleanly.</span>';
        echo '</div>';
        echo '<table class="wp-list-table widefat fixed striped presswire-table">';
        echo '<thead><tr><th>WordPress Field</th><th>Presswire Field</th></tr></thead><tbody>';
        foreach ($this->get_post_field_labels() as $target => $label) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong><span class="presswire-row-help">' . esc_html($this->get_post_field_help($target)) . '</span></td>';
            echo '<td><select name="' . esc_attr(Presswire_Field_Mapper::OPTION_NAME) . '[post_fields][' . esc_attr($target) . ']">' . $this->render_source_field_options($source_fields, $mapping['post_fields'][$target]) . '</select></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<div class="presswire-grid presswire-grid-2">';
        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Taxonomy</p><h2>Taxonomy Mapping</h2></div>';
        echo '<p class="presswire-card-note">Map remote sections and tags to local WordPress taxonomies.</p>';
        echo '</div>';
        echo '<table class="wp-list-table widefat fixed striped presswire-table">';
        echo '<thead><tr><th>WordPress Taxonomy</th><th>Presswire Field</th></tr></thead><tbody>';
        foreach ($this->get_taxonomy_labels() as $taxonomy => $label) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong><span class="presswire-row-help">' . esc_html($this->get_taxonomy_help($taxonomy)) . '</span></td>';
            echo '<td><select name="' . esc_attr(Presswire_Field_Mapper::OPTION_NAME) . '[taxonomies][' . esc_attr($taxonomy) . ']">' . $this->render_source_field_options($source_fields, $mapping['taxonomies'][$taxonomy]) . '</select></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Reference</p><h2>Available Remote Fields</h2></div>';
        echo '<p class="presswire-card-note">These are the normalized field names exposed by the importer.</p>';
        echo '</div>';
        echo '<p class="presswire-helper">The importer works against these normalized keys even when the upstream payload changes shape.</p>';
        echo '<div class="presswire-field-cloud">';
        foreach (array_keys($source_fields) as $field_name) {
            if ($field_name === '') {
                continue;
            }

            echo '<span class="presswire-chip">' . esc_html($field_name) . '</span>';
        }
        echo '</div>';
        echo '</section>';
        echo '</div>';

        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Custom Data</p><h2>Custom Field Mapping</h2></div>';
        echo '<p class="presswire-card-note">Store extra remote fields as WordPress post meta for downstream workflows.</p>';
        echo '</div>';
        echo '<ul class="presswire-mini-list">';
        echo '<li>Keep <code>release_key</code> mapped to a local meta key so update detection stays reliable.</li>';
        echo '<li>Store <code>publisher_notice</code> if you need the source disclaimer preserved for audit or display.</li>';
        echo '</ul>';
        echo '<table class="wp-list-table widefat fixed striped presswire-table" id="presswire-meta-mapping-table">';
        echo '<thead><tr><th>Presswire Field</th><th>WordPress Meta Key</th><th></th></tr></thead><tbody>';
        foreach ($mapping['meta_fields'] as $index => $row) {
            $this->render_meta_mapping_row($index, $row['source'], $row['meta_key'], $source_fields);
        }
        echo '</tbody></table>';
        echo '<p class="presswire-inline-actions"><button type="button" class="button button-secondary" id="presswire-add-meta-row">Add Custom Field</button></p>';
        echo '</section>';

        echo '<div class="presswire-submit-row">';
        submit_button('Save Importer Settings', 'primary presswire-save-button', 'submit', false);
        echo '</div>';
        echo '</form>';

        $this->render_meta_mapping_script($source_fields);
        $this->render_page_end();
    }

    public function category_mapper_page() {
        $client = new Presswire_API_Client();
        $remote_categories = $client->fetch_categories(true);
        $saved_mapping = get_option('presswire_category_mapping', []);
        $local_categories = get_terms(
            [
                'taxonomy' => 'category',
                'hide_empty' => false,
            ]
        );
        $remote_count = !empty($remote_categories) ? count($remote_categories) : 0;
        $mapped_count = 0;

        foreach ($saved_mapping as $term_id) {
            if (absint($term_id) > 0) {
                $mapped_count++;
            }
        }

        $this->render_page_start(
            'presswire-importer-category-mapper',
            'Taxonomy Sync',
            'Category Mapper',
            'Connect remote Presswire sections to your local WordPress categories so imports land in the right editorial buckets.',
            [
                [
                    'label' => 'View Releases',
                    'url' => admin_url('admin.php?page=presswire-importer'),
                    'style' => 'secondary',
                ],
                [
                    'label' => 'Importer Settings',
                    'url' => admin_url('admin.php?page=presswire-importer-settings'),
                    'style' => 'ghost',
                ],
            ],
            [
                [
                    'label' => 'Remote categories',
                    'value' => (string) $remote_count,
                ],
                [
                    'label' => 'Saved mappings',
                    'value' => (string) $mapped_count,
                ],
            ]
        );

        if ($client->get_last_error()) {
            $this->render_callout('warning', $client->get_last_error());
        }

        $this->render_status_cards(
            [
                [
                    'label' => 'Remote Categories',
                    'value' => (string) $remote_count,
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Mapped Categories',
                    'value' => (string) $mapped_count,
                    'accent' => 'surface',
                ],
                [
                    'label' => 'Unmapped Categories',
                    'value' => (string) max($remote_count - $mapped_count, 0),
                    'accent' => 'highlight',
                ],
                [
                    'label' => 'Local Categories',
                    'value' => is_wp_error($local_categories) ? '0' : (string) count($local_categories),
                    'accent' => 'surface',
                ],
            ]
        );

        echo '<form method="post" action="options.php">';
        settings_fields('presswire_category_mapping_settings');

        echo '<div class="presswire-grid presswire-grid-2 presswire-grid-tight">';
        echo '<section class="presswire-card presswire-card-compact">';
        echo '<div class="presswire-card-head presswire-card-head-compact">';
        echo '<div><p class="presswire-card-kicker">How it works</p><h2>Mapping logic</h2></div>';
        echo '</div>';
        echo '<ol class="presswire-checklist">';
        echo '<li>When a release section matches a saved remote key, the importer uses the mapped local category.</li>';
        echo '<li>If no mapping exists, the importer falls back to the taxonomy mapping in Settings.</li>';
        echo '<li>Manual keys let you cover codes, handles, or titles when the category feed is incomplete.</li>';
        echo '</ol>';
        echo '</section>';
        echo '<section class="presswire-card presswire-card-compact">';
        echo '<div class="presswire-card-head presswire-card-head-compact">';
        echo '<div><p class="presswire-card-kicker">Priority</p><h2>Recommended strategy</h2></div>';
        echo '</div>';
        echo '<ul class="presswire-mini-list">';
        echo '<li>Map major editorial sections first so the highest-volume releases land correctly.</li>';
        echo '<li>Prefer one local category per remote section to keep archive pages predictable.</li>';
        echo '<li>Use manual mappings for temporary or undocumented remote keys.</li>';
        echo '</ul>';
        echo '</section>';
        echo '</div>';

        if (!empty($remote_categories)) {
            echo '<section class="presswire-card">';
            echo '<div class="presswire-card-head">';
            echo '<div><p class="presswire-card-kicker">Remote Feed</p><h2>Detected Categories</h2></div>';
            echo '<p class="presswire-card-note">Map each remote key directly to a local category term.</p>';
            echo '</div>';
            echo '<table class="wp-list-table widefat fixed striped presswire-table">';
            echo '<thead><tr><th>Remote Key</th><th>Remote Title</th><th>Remote Handle</th><th>Local Category</th></tr></thead><tbody>';

            foreach ($remote_categories as $category) {
                $remote_key = $category['remote_key'];
                $selected_term_id = isset($saved_mapping[$remote_key]) ? absint($saved_mapping[$remote_key]) : 0;
                $state_class = $selected_term_id > 0 ? 'success' : 'muted';
                $state_label = $selected_term_id > 0 ? 'Mapped' : 'Unmapped';

                echo '<tr>';
                echo '<td><code class="presswire-key">' . esc_html($remote_key) . '</code><span class="presswire-row-help">Stable key used during import matching.</span></td>';
                echo '<td><span class="presswire-badge is-' . esc_attr($state_class) . '">' . esc_html($state_label) . '</span><strong>' . esc_html($category['section_title']) . '</strong></td>';
                echo '<td>' . esc_html($category['section_handle']) . '</td>';
                echo '<td><select name="presswire_category_mapping[' . esc_attr($remote_key) . ']"><option value="0">Do not map</option>' . $this->render_category_term_options($local_categories, $selected_term_id) . '</select></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</section>';
        } else {
            $this->render_empty_state('No remote categories were returned. You can still create manual mappings below.');
        }

        echo '<section class="presswire-card">';
        echo '<div class="presswire-card-head">';
        echo '<div><p class="presswire-card-kicker">Fallback</p><h2>Manual Category Keys</h2></div>';
        echo '<p class="presswire-card-note">Add section codes, handles, or titles manually when the remote category endpoint is unavailable or incomplete.</p>';
        echo '</div>';
        echo '<p class="presswire-helper">Use the exact remote value seen in the feed. Manual keys are merged into the same saved mapping store.</p>';
        echo '<table class="wp-list-table widefat fixed striped presswire-table" id="presswire-manual-category-table">';
        echo '<thead><tr><th>Remote Key</th><th>Local Category</th><th></th></tr></thead><tbody>';

        $manual_rows = [];
        foreach ($saved_mapping as $remote_key => $term_id) {
            $found_in_remote = false;

            foreach ($remote_categories as $category) {
                if ($category['remote_key'] === $remote_key) {
                    $found_in_remote = true;
                    break;
                }
            }

            if (!$found_in_remote) {
                $manual_rows[] = [
                    'remote_key' => $remote_key,
                    'term_id' => absint($term_id),
                ];
            }
        }

        if (empty($manual_rows)) {
            $manual_rows[] = [
                'remote_key' => '',
                'term_id' => 0,
            ];
        }

        foreach ($manual_rows as $index => $row) {
            $this->render_manual_category_row($index, $row['remote_key'], $row['term_id'], $local_categories);
        }

        echo '</tbody></table>';
        echo '<p class="presswire-inline-actions"><button type="button" class="button button-secondary" id="presswire-add-manual-category-row">Add Manual Mapping</button></p>';
        echo '</section>';

        echo '<div class="presswire-submit-row">';
        submit_button('Save Category Mapping', 'primary presswire-save-button', 'submit', false);
        echo '</div>';
        echo '</form>';

        $this->render_manual_category_script($local_categories);
        $this->render_page_end();
    }

    private function render_notice() {
        if (empty($_GET['presswire_notice']) || empty($_GET['presswire_message'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['presswire_notice']));
        $message = rawurldecode(wp_unslash($_GET['presswire_message']));
        $allowed = [
            'success' => 'success',
            'warning' => 'warning',
            'error' => 'error',
        ];

        if (!isset($allowed[$notice])) {
            return;
        }

        $this->render_callout($allowed[$notice], $message);
    }

    public function sanitize_category_mapping($mapping) {
        $sanitized = [];

        if (!is_array($mapping)) {
            return $sanitized;
        }

        foreach ($mapping as $remote_key => $term_id) {
            if ($remote_key === 'manual' && is_array($term_id)) {
                foreach ($term_id as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $manual_remote_key = sanitize_text_field((string) ($row['remote_key'] ?? ''));
                    $manual_term_id = absint($row['term_id'] ?? 0);

                    if ($manual_remote_key === '' || $manual_term_id <= 0) {
                        continue;
                    }

                    $sanitized[$manual_remote_key] = $manual_term_id;
                }

                continue;
            }

            $remote_key = sanitize_text_field((string) $remote_key);
            $term_id = absint($term_id);

            if ($remote_key === '' || $term_id <= 0) {
                continue;
            }

            $sanitized[$remote_key] = $term_id;
        }

        return $sanitized;
    }

    public function sanitize_api_key($value) {
        $value = sanitize_text_field((string) $value);

        if ($value === '') {
            return (string) get_option('presswire_api_key', '');
        }

        return $value;
    }

    public function print_admin_styles() {
        if (!$this->is_presswire_screen()) {
            return;
        }

        ?>
        <style>
            :root {
                --presswire-ink: #171a22;
                --presswire-subtle: #6b7280;
                --presswire-border: #d6dae2;
                --presswire-surface: #ffffff;
                --presswire-muted-surface: #f6f7fb;
                --presswire-brand: #b42318;
                --presswire-brand-deep: #7a1610;
                --presswire-brand-soft: #fef0ec;
                --presswire-highlight: #ffedd5;
                --presswire-shadow: 0 18px 46px rgba(23, 26, 34, 0.08);
                --presswire-radius: 18px;
            }

            #wpcontent {
                background: linear-gradient(180deg, #f4f6fa 0%, #edf1f7 100%);
            }

            .presswire-admin {
                margin: 24px 20px 0 0;
                color: var(--presswire-ink);
            }

            .presswire-admin *,
            .presswire-admin *::before,
            .presswire-admin *::after {
                box-sizing: border-box;
            }

            .presswire-hero {
                background:
                    radial-gradient(circle at top right, rgba(255, 255, 255, 0.18), transparent 28%),
                    linear-gradient(135deg, #171d29 0%, #232b3c 52%, #10151d 100%);
                border-radius: 24px;
                color: #fff;
                padding: 30px;
                box-shadow: var(--presswire-shadow);
                margin-bottom: 22px;
                position: relative;
                overflow: hidden;
            }

            .presswire-hero::after {
                content: "";
                position: absolute;
                inset: auto -70px -70px auto;
                width: 210px;
                height: 210px;
                border-radius: 999px;
                background: radial-gradient(circle, rgba(217, 45, 32, 0.46), rgba(217, 45, 32, 0));
                pointer-events: none;
            }

            .presswire-hero-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.8fr) minmax(240px, 0.8fr);
                gap: 18px;
                position: relative;
                z-index: 1;
            }

            .presswire-eyebrow {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.12);
                padding: 6px 12px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                margin: 0 0 12px;
            }

            .presswire-hero h1 {
                color: #fff;
                font-size: 30px;
                line-height: 1.1;
                margin: 0 0 10px;
            }

            .presswire-hero p {
                margin: 0;
                max-width: 760px;
                color: rgba(255, 255, 255, 0.82);
                font-size: 14px;
            }

            .presswire-hero-side {
                display: grid;
                gap: 12px;
                align-content: start;
            }

            .presswire-hero-meta {
                display: grid;
                gap: 12px;
            }

            .presswire-hero-meta-item {
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 18px;
                padding: 14px 16px;
                background: rgba(255, 255, 255, 0.06);
                backdrop-filter: blur(8px);
            }

            .presswire-hero-meta-item span {
                display: block;
                font-size: 12px;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: rgba(255, 255, 255, 0.6);
                margin-bottom: 6px;
                font-weight: 700;
            }

            .presswire-hero-meta-item strong {
                display: block;
                font-size: 16px;
                line-height: 1.4;
                color: #fff;
                word-break: break-word;
            }

            .presswire-nav {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin: 18px 0 0;
            }

            .presswire-nav a,
            .presswire-toolbar a {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 38px;
                padding: 0 14px;
                border-radius: 999px;
                text-decoration: none;
                color: rgba(255, 255, 255, 0.82);
                background: rgba(255, 255, 255, 0.08);
                transition: 0.18s ease;
            }

            .presswire-nav a:hover,
            .presswire-nav a:focus,
            .presswire-toolbar a:hover,
            .presswire-toolbar a:focus {
                color: #fff;
                background: rgba(255, 255, 255, 0.16);
            }

            .presswire-nav a.is-active,
            .presswire-toolbar a.is-primary {
                color: #fff;
                background: linear-gradient(135deg, #d92d20, #b42318);
                box-shadow: inset 0 0 0 1px rgba(255,255,255,0.1);
            }

            .presswire-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 18px;
            }

            .presswire-toolbar a.is-secondary {
                background: rgba(255, 255, 255, 0.12);
            }

            .presswire-toolbar a.is-ghost {
                background: transparent;
                border: 1px solid rgba(255, 255, 255, 0.16);
            }

            .presswire-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 14px;
                margin-bottom: 20px;
            }

            .presswire-stat {
                background: var(--presswire-surface);
                border: 1px solid var(--presswire-border);
                border-radius: var(--presswire-radius);
                padding: 18px 18px 16px;
                box-shadow: 0 10px 28px rgba(23, 26, 34, 0.04);
            }

            .presswire-stat.is-highlight {
                background: linear-gradient(180deg, #fff7ed 0%, #ffffff 100%);
                border-color: #fdba74;
            }

            .presswire-stat-label {
                margin: 0 0 6px;
                color: var(--presswire-subtle);
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }

            .presswire-stat-value {
                margin: 0;
                font-size: 18px;
                line-height: 1.3;
                font-weight: 700;
                word-break: break-word;
            }

            .presswire-card {
                background: var(--presswire-surface);
                border: 1px solid var(--presswire-border);
                border-radius: var(--presswire-radius);
                padding: 22px;
                box-shadow: 0 14px 36px rgba(23, 26, 34, 0.04);
                margin-bottom: 18px;
            }

            .presswire-card-table {
                padding: 0;
                overflow: hidden;
            }

            .presswire-card-compact {
                padding-top: 18px;
                padding-bottom: 18px;
            }

            .presswire-card-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 18px;
                padding: 22px 22px 0;
                margin-bottom: 18px;
            }

            .presswire-card-head h2 {
                margin: 0;
                font-size: 22px;
            }

            .presswire-card-kicker {
                margin: 0 0 8px;
                color: var(--presswire-brand);
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .presswire-card-note {
                max-width: 320px;
                margin: 0;
                color: var(--presswire-subtle);
                font-size: 13px;
                line-height: 1.5;
            }

            .presswire-card-head-compact {
                padding: 0;
                margin-bottom: 14px;
            }

            .presswire-grid {
                display: grid;
                gap: 18px;
                margin-bottom: 18px;
            }

            .presswire-grid-2 {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }

            .presswire-grid-tight {
                gap: 14px;
            }

            .presswire-form-table {
                margin-top: 0;
            }

            .presswire-form-table th {
                width: 180px;
                color: var(--presswire-ink);
            }

            .presswire-form-table td {
                color: var(--presswire-subtle);
            }

            .presswire-admin .regular-text,
            .presswire-admin select,
            .presswire-admin input[type="text"],
            .presswire-admin input[type="url"],
            .presswire-admin input[type="password"] {
                min-height: 42px;
                border-radius: 12px;
                border-color: var(--presswire-border);
                box-shadow: none;
            }

            .presswire-admin .regular-text,
            .presswire-admin input[type="text"],
            .presswire-admin input[type="url"],
            .presswire-admin input[type="password"] {
                width: min(100%, 480px);
            }

            .presswire-admin select {
                min-width: 220px;
            }

            .presswire-admin .code {
                font-family: Consolas, Monaco, monospace;
            }

            .presswire-table-wrap {
                overflow-x: auto;
            }

            .presswire-table {
                margin: 0;
                border: 0;
                box-shadow: none;
            }

            .presswire-table thead th {
                background: var(--presswire-muted-surface);
                color: var(--presswire-subtle);
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                border-bottom: 1px solid var(--presswire-border);
                padding: 14px 16px;
            }

            .presswire-table td {
                padding: 16px;
                vertical-align: middle;
            }

            .presswire-release-cell strong {
                display: block;
                font-size: 14px;
                line-height: 1.45;
                margin-bottom: 6px;
            }

            .presswire-subline {
                color: var(--presswire-subtle);
                font-size: 12px;
                margin-bottom: 6px;
            }

            .presswire-key {
                display: inline-flex;
                padding: 4px 8px;
                border-radius: 8px;
                background: var(--presswire-muted-surface);
                border: 1px solid var(--presswire-border);
                font-size: 12px;
            }

            .presswire-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 10px;
                border-radius: 999px;
                background: var(--presswire-brand-soft);
                color: var(--presswire-brand-deep);
                font-size: 12px;
                font-weight: 700;
            }

            .presswire-chip-muted {
                background: #eef2ff;
                color: #4338ca;
            }

            .presswire-badge {
                display: inline-flex;
                align-items: center;
                margin: 0 0 10px;
                padding: 5px 10px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                border: 1px solid transparent;
            }

            .presswire-badge.is-success {
                background: #ecfdf3;
                border-color: #86efac;
                color: #166534;
            }

            .presswire-badge.is-muted {
                background: #eff3f8;
                border-color: #d6dae2;
                color: #475467;
            }

            .presswire-field-cloud {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }

            .presswire-helper {
                margin: 0 0 16px;
                color: var(--presswire-subtle);
                line-height: 1.6;
            }

            .presswire-mini-list,
            .presswire-checklist {
                margin: 0;
                padding-left: 18px;
                color: var(--presswire-subtle);
            }

            .presswire-mini-list li,
            .presswire-checklist li {
                margin: 0 0 10px;
                line-height: 1.55;
            }

            .presswire-definition-list {
                display: grid;
                gap: 12px;
                margin: 0;
            }

            .presswire-definition-list div {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--presswire-border);
            }

            .presswire-definition-list div:last-child {
                padding-bottom: 0;
                border-bottom: 0;
            }

            .presswire-definition-list dt {
                color: var(--presswire-subtle);
                font-size: 13px;
            }

            .presswire-definition-list dd {
                margin: 0;
                font-weight: 700;
                color: var(--presswire-ink);
            }

            .presswire-inline-banner {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                padding: 12px 14px;
                border-radius: 14px;
                margin-bottom: 16px;
                background: #fff7ed;
                border: 1px solid #fdba74;
                color: #7c2d12;
            }

            .presswire-inline-banner strong {
                color: #9a3412;
            }

            .presswire-row-help {
                display: block;
                margin-top: 4px;
                color: var(--presswire-subtle);
                font-size: 12px;
                line-height: 1.5;
                font-weight: 400;
            }

            .presswire-inline-actions,
            .presswire-submit-row {
                margin: 18px 0 0;
            }

            .presswire-submit-row .button-primary,
            .presswire-admin .button.button-primary {
                background: linear-gradient(135deg, #d92d20, #b42318);
                border-color: #8f1d14;
                color: #fff;
            }

            .presswire-admin .button.button-secondary {
                border-color: var(--presswire-border);
                color: var(--presswire-ink);
                background: #fff;
            }

            .presswire-actions {
                white-space: nowrap;
            }

            .presswire-callout {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                border-radius: 16px;
                padding: 14px 16px;
                margin-bottom: 18px;
                border: 1px solid var(--presswire-border);
                background: #fff;
            }

            .presswire-callout strong {
                display: block;
                margin-bottom: 4px;
            }

            .presswire-callout p {
                margin: 0;
                color: var(--presswire-subtle);
            }

            .presswire-callout.is-success {
                background: #ecfdf3;
                border-color: #86efac;
            }

            .presswire-callout.is-warning {
                background: #fff7ed;
                border-color: #fdba74;
            }

            .presswire-callout.is-error {
                background: #fef2f2;
                border-color: #fca5a5;
            }

            .presswire-empty {
                text-align: center;
                padding: 34px 20px;
                border: 1px dashed var(--presswire-border);
                border-radius: var(--presswire-radius);
                background: var(--presswire-surface);
                color: var(--presswire-subtle);
                margin-bottom: 18px;
            }

            @media (max-width: 960px) {
                .presswire-hero-grid {
                    grid-template-columns: 1fr;
                }

                .presswire-card-head {
                    flex-direction: column;
                }

                .presswire-card-note {
                    max-width: none;
                }

                .presswire-definition-list div {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 4px;
                }
            }
        </style>
        <?php
    }

    private function render_category_term_options($local_categories, $selected_term_id) {
        $html = '';

        if (is_wp_error($local_categories)) {
            return $html;
        }

        foreach ($local_categories as $term) {
            $html .= '<option value="' . esc_attr((string) $term->term_id) . '"' . selected($selected_term_id, $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
        }

        return $html;
    }

    private function render_manual_category_row($index, $remote_key, $term_id, $local_categories) {
        echo '<tr>';
        echo '<td><input type="text" name="presswire_category_mapping[manual][' . esc_attr((string) $index) . '][remote_key]" value="' . esc_attr($remote_key) . '" placeholder="Remote section key"></td>';
        echo '<td><select name="presswire_category_mapping[manual][' . esc_attr((string) $index) . '][term_id]"><option value="0">Do not map</option>' . $this->render_category_term_options($local_categories, $term_id) . '</select></td>';
        echo '<td><button type="button" class="button-link-delete presswire-remove-manual-category-row">Remove</button></td>';
        echo '</tr>';
    }

    private function render_status_cards($cards) {
        echo '<section class="presswire-stats">';
        foreach ($cards as $card) {
            $accent = !empty($card['accent']) && $card['accent'] === 'highlight' ? ' is-highlight' : '';
            echo '<article class="presswire-stat' . esc_attr($accent) . '">';
            echo '<p class="presswire-stat-label">' . esc_html($card['label']) . '</p>';
            echo '<p class="presswire-stat-value">' . esc_html($card['value']) . '</p>';
            echo '</article>';
        }
        echo '</section>';
    }

    private function render_page_start($active_page, $eyebrow, $title, $description, $actions = [], $meta = []) {
        echo '<div class="wrap presswire-admin">';
        echo '<section class="presswire-hero">';
        echo '<div class="presswire-hero-grid">';
        echo '<div>';
        echo '<p class="presswire-eyebrow">' . esc_html($eyebrow) . '</p>';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '<nav class="presswire-nav">';
        $pages = [
            'presswire-importer' => 'Releases',
            'presswire-importer-settings' => 'Settings',
            'presswire-importer-category-mapper' => 'Category Mapper',
        ];
        foreach ($pages as $page_slug => $label) {
            $class = $active_page === $page_slug ? 'is-active' : '';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . $page_slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if (!empty($actions)) {
            echo '<div class="presswire-toolbar">';
            foreach ($actions as $action) {
                if (empty($action['label']) || empty($action['url'])) {
                    continue;
                }

                $style = !empty($action['style']) ? sanitize_html_class('is-' . $action['style']) : 'is-secondary';
                echo '<a class="' . esc_attr($style) . '" href="' . esc_url($action['url']) . '">' . esc_html($action['label']) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<aside class="presswire-hero-side">';
        if (!empty($meta)) {
            echo '<div class="presswire-hero-meta">';
            foreach ($meta as $item) {
                if (empty($item['label'])) {
                    continue;
                }

                echo '<div class="presswire-hero-meta-item">';
                echo '<span>' . esc_html($item['label']) . '</span>';
                echo '<strong>' . esc_html((string) ($item['value'] ?? '')) . '</strong>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</aside>';
        echo '</div>';
        echo '</section>';
    }

    private function render_page_end() {
        echo '</div>';
    }

    private function render_callout($type, $message) {
        $type = in_array($type, ['success', 'warning', 'error'], true) ? $type : 'warning';
        $titles = [
            'success' => 'Saved',
            'warning' => 'Attention',
            'error' => 'Problem',
        ];

        echo '<div class="presswire-callout is-' . esc_attr($type) . '">';
        echo '<div>';
        echo '<strong>' . esc_html($titles[$type]) . '</strong>';
        echo '<p>' . esc_html($message) . '</p>';
        echo '</div>';
        echo '</div>';
    }

    private function render_empty_state($message) {
        echo '<div class="presswire-empty">' . esc_html($message) . '</div>';
    }

    private function has_api_configuration() {
        return get_option('presswire_api_endpoint') && get_option('presswire_provider_id') && get_option('presswire_api_key');
    }

    private function render_meta_mapping_script($source_fields) {
        ?>
        <script type="text/html" id="tmpl-presswire-meta-row">
            <?php
            ob_start();
            $this->render_meta_mapping_row('__INDEX__', '', '', $source_fields);
            echo trim(ob_get_clean());
            ?>
        </script>
        <script>
            (function () {
                var addButton = document.getElementById('presswire-add-meta-row');
                var tableBody = document.querySelector('#presswire-meta-mapping-table tbody');
                var template = document.getElementById('tmpl-presswire-meta-row');

                if (!addButton || !tableBody || !template) {
                    return;
                }

                addButton.addEventListener('click', function () {
                    var index = tableBody.querySelectorAll('tr').length;
                    var html = template.innerHTML.replace(/__INDEX__/g, index);
                    tableBody.insertAdjacentHTML('beforeend', html);
                });

                tableBody.addEventListener('click', function (event) {
                    var button = event.target.closest('.presswire-remove-meta-row');

                    if (!button) {
                        return;
                    }

                    event.preventDefault();
                    var row = button.closest('tr');

                    if (row) {
                        row.remove();
                    }
                });
            }());
        </script>
        <?php
    }

    private function render_manual_category_script($local_categories) {
        ?>
        <script type="text/html" id="tmpl-presswire-manual-category-row">
            <?php
            ob_start();
            $this->render_manual_category_row('__INDEX__', '', 0, $local_categories);
            echo trim(ob_get_clean());
            ?>
        </script>
        <script>
            (function () {
                var addButton = document.getElementById('presswire-add-manual-category-row');
                var tableBody = document.querySelector('#presswire-manual-category-table tbody');
                var template = document.getElementById('tmpl-presswire-manual-category-row');

                if (!addButton || !tableBody || !template) {
                    return;
                }

                addButton.addEventListener('click', function () {
                    var index = tableBody.querySelectorAll('tr').length;
                    var html = template.innerHTML.replace(/__INDEX__/g, index);
                    tableBody.insertAdjacentHTML('beforeend', html);
                });

                tableBody.addEventListener('click', function (event) {
                    var button = event.target.closest('.presswire-remove-manual-category-row');

                    if (!button) {
                        return;
                    }

                    event.preventDefault();
                    var row = button.closest('tr');

                    if (row) {
                        row.remove();
                    }
                });
            }());
        </script>
        <?php
    }

    private function is_presswire_screen() {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        if (!$screen || empty($screen->id)) {
            return false;
        }

        return strpos($screen->id, 'presswire-importer') !== false;
    }

    private function get_post_type_options() {
        $post_types = get_post_types(
            [
                'show_ui' => true,
            ],
            'objects'
        );
        $options = [];

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->labels->singular_name;
        }

        return $options;
    }

    private function get_post_status_options() {
        return [
            'publish' => 'Publish',
            'draft' => 'Draft',
            'pending' => 'Pending Review',
            'private' => 'Private',
        ];
    }

    private function get_post_field_labels() {
        return [
            'post_title' => 'Post Title',
            'post_content' => 'Post Content',
            'post_excerpt' => 'Post Excerpt',
            'post_name' => 'Post Slug',
            'post_date' => 'Post Date',
        ];
    }

    private function get_taxonomy_labels() {
        return [
            'category' => 'Categories',
            'post_tag' => 'Tags',
        ];
    }

    private function get_post_field_help($target) {
        $help = [
            'post_title' => 'Used in WordPress lists, permalinks, and SEO surfaces.',
            'post_content' => 'Main article body saved to the editor content area.',
            'post_excerpt' => 'Short summary for archives, cards, and previews.',
            'post_name' => 'URL-safe slug used in the post permalink.',
            'post_date' => 'Controls the published timestamp on the WordPress side.',
        ];

        return isset($help[$target]) ? $help[$target] : '';
    }

    private function get_taxonomy_help($taxonomy) {
        $help = [
            'category' => 'Primary content grouping used for archive and editorial organization.',
            'post_tag' => 'Keyword-style classification for discovery and internal filtering.',
        ];

        return isset($help[$taxonomy]) ? $help[$taxonomy] : '';
    }

    private function render_source_field_options($source_fields, $selected) {
        $html = '';

        foreach ($source_fields as $value => $label) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }

        return $html;
    }

    private function render_meta_mapping_row($index, $source, $meta_key, $source_fields) {
        echo '<tr>';
        echo '<td><select name="' . esc_attr(Presswire_Field_Mapper::OPTION_NAME) . '[meta_fields][' . esc_attr((string) $index) . '][source]">' . $this->render_source_field_options($source_fields, $source) . '</select></td>';
        echo '<td><input type="text" name="' . esc_attr(Presswire_Field_Mapper::OPTION_NAME) . '[meta_fields][' . esc_attr((string) $index) . '][meta_key]" value="' . esc_attr($meta_key) . '" placeholder="custom_meta_key"></td>';
        echo '<td><button type="button" class="button-link-delete presswire-remove-meta-row">Remove</button></td>';
        echo '</tr>';
    }
}
