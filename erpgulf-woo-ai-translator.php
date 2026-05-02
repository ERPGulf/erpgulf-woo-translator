<?php
/**
 * Plugin Name: ERPGulf AI Translate
 * Version:     1.0.0
 * Author:      Farook K — https://medium.com/nothing-big
 * Description: Translate WooCommerce products from Arabic to English
 *              using your choice of AI — Gemini, ChatGPT, or Claude.
 *              Saves translations directly into the WPML English product.
 *              Zero WPML credits used.
 *
 * ═══════════════════════════════════════════════════════════════════
 * ADDING A NEW AI PROVIDER
 * ═══════════════════════════════════════════════════════════════════
 *
 * 1. Create {name}-provider.php with function:
 *      erpgulf_gt_translate_{name}( string $prompt, array $settings ): string|WP_Error
 *
 * 2. Add require_once below.
 *
 * 3. Add one entry to erpgulf_gt_ai_providers().
 *
 * 4. Admin → ERPGulf AI Translate → Active AI Provider → select → Save.
 *
 * ═══════════════════════════════════════════════════════════════════
 * DEVELOPER HOOKS
 * ═══════════════════════════════════════════════════════════════════
 *
 * FILTERS
 *   erpgulf_gt_fields          ( $fields, $post_id )
 *   erpgulf_gt_prompt          ( $prompt, $field, $text, $post_id )
 *   erpgulf_gt_active_provider ( $provider )
 *   erpgulf_gt_translated_text ( $translated, $original, $field, $post_id )
 *
 * ACTIONS
 *   erpgulf_gt_before_translate   ( $post_id )
 *   erpgulf_gt_after_translate    ( $post_id, $results )
 *   erpgulf_gt_translation_failed ( $post_id, $field, $error )
 * ═══════════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────
// LOAD PROVIDERS
// ─────────────────────────────────────────────────────────────────

require_once plugin_dir_path(__FILE__) . 'gemini-provider.php';
require_once plugin_dir_path(__FILE__) . 'openai-provider.php';
require_once plugin_dir_path(__FILE__) . 'claude-provider.php';

// ─────────────────────────────────────────────────────────────────
// PROVIDER REGISTRY
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_ai_providers(): array {
    return [
        'gemini' => [
            'label'         => 'Google Gemini',
            'models'        => [],    // fetched live from API
            'default_model' => 'gemini-2.0-flash',
            'key_label'     => 'Gemini API Key',
            'key_option'    => 'erpgulf_gt_gemini_api_key',
            'model_option'  => 'erpgulf_gt_gemini_model',
            'key_url'       => 'https://aistudio.google.com/app/apikey',
            'key_hint'      => 'Free at aistudio.google.com. No billing required for Flash.',
            'key_placeholder' => 'AIzaSy...',
        ],
        'openai' => [
            'label'         => 'OpenAI ChatGPT',
            'models'        => [
                'gpt-4o-mini'   => 'GPT-4o Mini — Fast & affordable (recommended)',
                'gpt-4o'        => 'GPT-4o — Best quality',
                'gpt-3.5-turbo' => 'GPT-3.5 Turbo — Budget option',
            ],
            'default_model' => 'gpt-4o-mini',
            'key_label'     => 'OpenAI API Key',
            'key_option'    => 'erpgulf_gt_openai_api_key',
            'model_option'  => 'erpgulf_gt_openai_model',
            'key_url'       => 'https://platform.openai.com/api-keys',
            'key_hint'      => 'Requires a paid OpenAI account.',
            'key_placeholder' => 'sk-...',
        ],
        'claude' => [
            'label'         => 'Anthropic Claude',
            'models'        => [
                'claude-haiku-4-5-20251001' => 'Claude Haiku — Fast & affordable (recommended)',
                'claude-sonnet-4-6'         => 'Claude Sonnet — Best quality',
            ],
            'default_model' => 'claude-haiku-4-5-20251001',
            'key_label'     => 'Anthropic API Key',
            'key_option'    => 'erpgulf_gt_claude_api_key',
            'model_option'  => 'erpgulf_gt_claude_model',
            'key_url'       => 'https://console.anthropic.com/settings/keys',
            'key_hint'      => 'Requires an Anthropic account.',
            'key_placeholder' => 'sk-ant-...',
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────
// HELPER — active provider key
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_active_provider(): string {
    $saved    = get_option( 'erpgulf_gt_active_provider', 'gemini' );
    $provider = (string) apply_filters( 'erpgulf_gt_active_provider', $saved );
    $registry = erpgulf_gt_ai_providers();
    return array_key_exists( $provider, $registry ) ? $provider : 'gemini';
}

// ─────────────────────────────────────────────────────────────────
// FIELD DISCOVERY — safe, only runs on our settings page
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_get_all_product_fields(): array {

    global $wpdb;

    // ── Guard: only run when our settings page is open ────────────
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'erpgulf-woo-ai-translator' ) === false ) {
        return [];
    }

    // ── Standard fields — always available ────────────────────────
    $standard = [
        'title'   => [ 'label' => 'Product Title',      'sample' => '' ],
        'content' => [ 'label' => 'Product Description', 'sample' => '' ],
        'excerpt' => [ 'label' => 'Short Description',   'sample' => '' ],
    ];

    $groups = [ '📝 Standard Fields' => $standard ];

    // ── Custom meta fields — queried safely ───────────────────────
    try {

        $excluded_prefixes = [
            '_price', '_regular_price', '_sale_price', '_stock',
            '_sku', '_weight', '_length', '_width', '_height',
            '_thumbnail_id', '_product_image_gallery', '_downloadable',
            '_virtual', '_manage_stock', '_backorders', '_sold_individually',
            '_upsell_ids', '_crosssell_ids', '_product_attributes',
            '_default_attributes', '_variation_', '_children',
            'total_sales', '_wc_', '_wcfm_', '_yoast', 'rank_math',
            '_aioseop', 'seopress', '_edit_last', '_edit_lock',
            'wpml_', 'icl_', '_icl_', 'wcml_', 'slide_template',
            '_wp_page_template', 'tm_', '_purchase_note_',
            // WordPress system
            '_oembed_',             // embedded media cache keys
            '_encloseme',
            '_ping_status',
            // WPML internal — both with and without underscore prefix
            '_icl_compiler',
            'last_translation',     // without underscore
            '_last_translation',    // with underscore
            '_wpml_',
            'wpml_media',
        ];

        // Query 1: non-underscore keys — real custom fields like manufacturer_brand
        // Exclude repeater sub-fields at SQL level (_N_ pattern) so LIMIT is not wasted
        $raw_keys_custom = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_value != ''
               AND LENGTH( pm.meta_value ) BETWEEN 2 AND 500
               AND pm.meta_key NOT LIKE '\_%'
               AND pm.meta_key NOT REGEXP '_[0-9]+_'
             ORDER BY pm.meta_key ASC
             LIMIT 100"
        );

        $raw_keys_underscore = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_value != ''
               AND LENGTH( pm.meta_value ) BETWEEN 2 AND 500
               AND pm.meta_key LIKE '\_%'
               AND pm.meta_key NOT REGEXP '_[0-9]+_'
             ORDER BY pm.meta_key ASC
             LIMIT 100"
        );

        $raw_keys = array_merge(
            (array) $raw_keys_custom,
            (array) $raw_keys_underscore
        );

        $custom_group = [];

        foreach ( (array) $raw_keys as $meta_key ) {

            // Skip excluded prefixes
            foreach ( $excluded_prefixes as $prefix ) {
                if ( str_starts_with( $meta_key, $prefix ) ) continue 2;
            }

            // Skip ACF repeater sub-fields — pattern: parent_0_subfield, parent_1_subfield
            if ( preg_match( '/_\d+_[a-zA-Z]/', $meta_key ) ) continue;

            // Get up to 3 sample values — avoids false negatives
            $samples = $wpdb->get_col( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                   AND meta_value != ''
                   AND LENGTH( meta_value ) BETWEEN 2 AND 500
                 LIMIT 3",
                $meta_key
            ) );

            // Find first sample that looks like real translatable text
            $sample = '';
            foreach ( $samples as $s ) {
                $s = trim( $s );
                if ( empty( $s ) )                                    continue;
                if ( is_numeric( $s ) )                               continue;
                if ( str_starts_with( $s, 'a:' ) )                   continue;
                if ( str_starts_with( $s, '{' ) )                     continue;
                if ( str_starts_with( $s, 'N;' ) )                    continue;
                if ( str_starts_with( $s, 'field_' ) )                continue;
                if ( preg_match( '/^[a-f0-9]{20,}$/i', $s ) )        continue;
                if ( in_array( $s, [
                    'native-editor', 'block-editor', 'translation-editor',
                    'yes', 'no', '1', '0', 'on', 'off',
                    'publish', 'draft', 'private', 'pending',
                ], true ) )                                            continue;
                $sample = $s;
                break;
            }

            if ( empty( $sample ) ) continue;

            $custom_group[ 'meta:' . $meta_key ] = [
                'label'  => ucwords( str_replace( [ '_', '-' ], ' ', ltrim( $meta_key, '_' ) ) ),
                'sample' => $sample,
            ];
        }

        if ( ! empty( $custom_group ) ) {
            $groups['⚙️ Custom & ACF Fields'] = $custom_group;
        }

        // ── Repeater fields ───────────────────────────────────────────
        $repeater_keys = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_key NOT LIKE '\_%'
               AND pm.meta_value REGEXP '^[0-9]+$'
               AND CAST(pm.meta_value AS UNSIGNED) > 0
             ORDER BY pm.meta_key ASC
             LIMIT 20"
        );

        $repeater_group = [];

        foreach ( (array) $repeater_keys as $rkey ) {

            $sub_count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT meta_key) FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE %s AND meta_key NOT LIKE '\_%'",
                $wpdb->esc_like( $rkey . '_0_' ) . '%'
            ) );

            if ( ! $sub_count ) continue;

            $sub_fields = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT SUBSTRING_INDEX(meta_key, '_', -1) as sub
                 FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE %s
                   AND meta_key NOT LIKE '\_%'
                   AND meta_value != ''
                   AND LENGTH(meta_value) BETWEEN 2 AND 300
                 LIMIT 20",
                $wpdb->esc_like( $rkey . '_0_' ) . '%'
            ) );

            $translatable_subs = [];
            foreach ( $sub_fields as $sub ) {
                if ( in_array( $sub, [ 'years', 'year', 'engine_size', 'size', 'id' ], true ) ) continue;
                $sample = $wpdb->get_var( $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = %s AND meta_value != ''
                     LIMIT 1",
                    $rkey . '_0_' . $sub
                ) );
                if ( ! $sample ) continue;
                if ( is_numeric( $sample ) ) continue;
                if ( preg_match( '/^[\d,\s\-]+$/', $sample ) ) continue;
                $translatable_subs[] = $sub;
            }

            if ( empty( $translatable_subs ) ) continue;

            $label = ucwords( str_replace( '_', ' ', $rkey ) );
            $repeater_group[ 'repeater:' . $rkey ] = [
                'label'  => $label,
                'sample' => 'Repeater — translates: ' . implode( ', ', $translatable_subs ),
            ];
        }

        if ( ! empty( $repeater_group ) ) {
            $groups['🔁 Repeater Fields'] = $repeater_group;
        }

    } catch ( \Throwable $e ) {
        error_log( 'ERPGulf AI Translate: field discovery error — ' . $e->getMessage() );
    }

    return $groups;
}

// ─────────────────────────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_menu_page(
        'ERPGulf AI Translate',
        'ERPGulf AI Translate',
        'manage_options',
        'erpgulf-woo-ai-translator',
        'erpgulf_gt_settings_render',
        'dashicons-translation',
        56
    );
} );

// ─────────────────────────────────────────────────────────────────
// SETTINGS PAGE
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_settings_render() {

    $registry = erpgulf_gt_ai_providers();

    if ( isset( $_POST['save_gt_settings'] ) && check_admin_referer( 'erpgulf_gt_save' ) ) {

        update_option( 'erpgulf_gt_active_provider', sanitize_key( $_POST['gt_active_provider'] ?? 'gemini' ) );
        update_option( 'erpgulf_gt_fields',          array_map( 'sanitize_text_field', (array)( $_POST['gt_fields'] ?? [] ) ) );
        update_option( 'erpgulf_gt_source_lang',     sanitize_text_field( trim( $_POST['gt_source_lang'] ?? 'Arabic' ) ) );
        update_option( 'erpgulf_gt_target_lang',     sanitize_text_field( trim( $_POST['gt_target_lang'] ?? 'English' ) ) );

        foreach ( $registry as $key => $info ) {
            if ( isset( $_POST[ "gt_{$key}_api_key" ] ) ) {
                update_option( $info['key_option'],   sanitize_text_field( trim( $_POST[ "gt_{$key}_api_key" ] ) ) );
            }
            if ( isset( $_POST[ "gt_{$key}_model" ] ) ) {
                update_option( $info['model_option'], sanitize_text_field( trim( $_POST[ "gt_{$key}_model" ] ) ) );
            }
        }

        echo '<div class="updated"><p>✅ Settings saved.</p></div>';
    }

    $active_provider = erpgulf_gt_active_provider();
    $fields          = get_option( 'erpgulf_gt_fields', [ 'title', 'content', 'excerpt' ] );
    $source_lang     = get_option( 'erpgulf_gt_source_lang', 'Arabic' );
    $target_lang     = get_option( 'erpgulf_gt_target_lang', 'English' );
    $all_fields      = erpgulf_gt_get_all_product_fields();
    ?>

    <div class="wrap">
        <h1>🤖 ERPGulf AI Translate</h1>

        <div style="background:#e8f4fd;border:1px solid #007cba;border-radius:4px;padding:16px 20px;margin-bottom:24px;max-width:900px;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <strong>How it works:</strong> Open any product → find the
                <strong>ERPGulf AI Translate</strong> box on the right sidebar →
                click <strong>Translate to English</strong>. Zero WPML credits used.
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=erpgulf-gt-bulk' ) ); ?>"
               class="button button-primary"
               style="white-space:nowrap;margin-left:20px;flex-shrink:0;">
                🚀 Bulk Translate →
            </a>
        </div>

        <form method="post" style="max-width:900px;">
            <?php wp_nonce_field( 'erpgulf_gt_save' ); ?>

            {{!-- ── Active Provider ── --}}
            <div style="background:#e8f4fd;padding:20px;border:2px solid #007cba;border-radius:4px;margin-bottom:24px;">
                <h3 style="margin-top:0;">⚙️ Active AI Provider</h3>
                <p style="color:#555;font-size:13px;margin-top:0;">
                    Choose which AI handles translations. All credentials are saved — only the selected one is used.
                </p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <?php foreach ( $registry as $key => $info ): ?>
                        <?php $is_active = ( $active_provider === $key ); ?>
                        <label style="
                            flex:1; min-width:180px;
                            border:2px solid <?php echo $is_active ? '#007cba' : '#ddd'; ?>;
                            border-radius:8px; padding:14px 16px; cursor:pointer;
                            background:<?php echo $is_active ? '#f0f7ff' : '#fff'; ?>;
                            display:flex; align-items:center; gap:10px;">
                            <input type="radio" name="gt_active_provider"
                                   value="<?php echo esc_attr( $key ); ?>"
                                   <?php checked( $active_provider, $key ); ?>>
                            <div>
                                <strong><?php echo esc_html( $info['label'] ); ?></strong>
                                <?php if ( $is_active ): ?>
                                    <span style="font-size:11px;background:#007cba;color:#fff;padding:1px 6px;border-radius:8px;margin-left:6px;">Active</span>
                                <?php endif; ?>
                                <p style="margin:4px 0 0;font-size:12px;color:#666;">
                                    <?php echo esc_html( $info['default_model'] ); ?>
                                </p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            {{!-- ── Translation Direction ── --}}
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🌐 Translation Direction</h3>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="width:180px;">Source Language</th>
                        <td>
                            <input type="text" name="gt_source_lang"
                                   value="<?php echo esc_attr( $source_lang ); ?>"
                                   class="regular-text" placeholder="Arabic">
                            <p class="description">Language your products are written in.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Target Language</th>
                        <td>
                            <input type="text" name="gt_target_lang"
                                   value="<?php echo esc_attr( $target_lang ); ?>"
                                   class="regular-text" placeholder="English">
                            <p class="description">Must match your WPML language name.</p>
                        </td>
                    </tr>
                </table>
            </div>

            {{!-- ── Fields to Translate ── --}}
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin-top:0;">📋 Fields to Translate</h3>
                <p style="color:#666;font-size:13px;margin-top:0;">
                    Select which fields should be translated. Custom fields are discovered from your existing published products.
                </p>

                <?php if ( empty( $all_fields ) ): ?>
                    <p style="color:#888;">Loading fields...</p>
                <?php else: ?>
                    <?php foreach ( $all_fields as $group_label => $group_fields ): ?>
                        <div style="margin-bottom:20px;">
                            <h4 style="margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid #eee;color:#444;">
                                <?php echo esc_html( $group_label ); ?>
                            </h4>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;">
                                <?php foreach ( $group_fields as $fkey => $finfo ):
                                    $checked = in_array( $fkey, $fields, true );
                                ?>
                                    <label style="
                                        display:flex; align-items:flex-start; gap:8px;
                                        padding:8px 10px;
                                        border:1px solid <?php echo $checked ? '#007cba' : '#e5e5e5'; ?>;
                                        border-radius:4px; cursor:pointer;
                                        background:<?php echo $checked ? '#f0f7ff' : '#fff'; ?>;">
                                        <input type="checkbox" name="gt_fields[]"
                                               value="<?php echo esc_attr( $fkey ); ?>"
                                               style="margin-top:2px;"
                                               <?php checked( $checked ); ?>>
                                        <div>
                                            <strong style="font-size:13px;display:block;">
                                                <?php echo esc_html( $finfo['label'] ); ?>
                                            </strong>
                                            <?php if ( ! empty( $finfo['sample'] ) ): ?>
                                                <span style="font-size:11px;color:#888;display:block;max-width:200px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                                                    <?php echo esc_html( mb_substr( strip_tags( $finfo['sample'] ), 0, 45 ) ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            {{!-- ── Credentials per provider ── --}}
            <?php foreach ( $registry as $key => $info ):
                $saved_key   = get_option( $info['key_option'], '' );
                $saved_model = get_option( $info['model_option'], $info['default_model'] );
                $is_active   = ( $active_provider === $key );

                if ( $key === 'gemini' ) {
                    $live_models = $saved_key ? erpgulf_gt_get_gemini_models( $saved_key ) : [];
                    if ( empty( $live_models ) ) {
                        $live_models = [
                            'gemini-2.0-flash'      => 'Gemini 2.0 Flash (default)',
                            'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
                            'gemini-1.5-flash'      => 'Gemini 1.5 Flash',
                        ];
                    }
                    $display_models = $live_models;
                } else {
                    $display_models = $info['models'];
                }
            ?>
            <div style="background:#fff;padding:20px;border:1px solid <?php echo $is_active ? '#007cba' : '#ccd0d4'; ?>;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin-top:0;">
                    🔑 <?php echo esc_html( $info['label'] ); ?> Credentials
                    <?php if ( $is_active ): ?>
                        <span style="font-size:12px;font-weight:normal;background:#007cba;color:#fff;padding:2px 8px;border-radius:10px;margin-left:8px;">Active</span>
                    <?php endif; ?>
                </h3>
                <p style="color:#666;font-size:13px;margin-top:0;">
                    <?php echo esc_html( $info['key_hint'] ); ?>
                    &nbsp;<a href="<?php echo esc_url( $info['key_url'] ); ?>" target="_blank">Get API key →</a>
                </p>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="width:180px;"><?php echo esc_html( $info['key_label'] ); ?></th>
                        <td>
                            <input type="password"
                                   name="gt_<?php echo esc_attr( $key ); ?>_api_key"
                                   value="<?php echo esc_attr( $saved_key ); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr( $info['key_placeholder'] ); ?>">
                            <?php if ( $saved_key ): ?>
                                <span style="color:green;margin-left:10px;">✅ Key saved</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Model</th>
                        <td>
                            <select name="gt_<?php echo esc_attr( $key ); ?>_model" style="min-width:360px;">
                                <?php foreach ( $display_models as $mkey => $mlabel ): ?>
                                    <option value="<?php echo esc_attr( $mkey ); ?>"
                                        <?php selected( $saved_model, $mkey ); ?>>
                                        <?php echo esc_html( $mlabel ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $key === 'gemini' ): ?>
                                <p class="description">
                                    <?php echo $saved_key
                                        ? count( $display_models ) . ' models available for your API key.'
                                        : 'Enter your API key and save to load available models.'; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endforeach; ?>

            <p class="submit">
                <input type="submit" name="save_gt_settings"
                       class="button button-primary"
                       value="Save Settings">
            </p>
        </form>

        {{!-- ── Tools & Maintenance ── --}}
        <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;margin-top:20px;max-width:900px;">
            <h3 style="margin-top:0;">🔧 Tools &amp; Maintenance</h3>

            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th style="width:180px;">Product Lookup Table</th>
                    <td>
                        <button type="button" id="erpgulf-gt-regen-btn" class="button button-secondary">
                            ♻️ Regenerate Lookup Table
                        </button>
                        <p class="description" style="margin-top:6px;">
                            Fixes admin SKU search for all translated products.
                            Run this once after bulk translation or if products are not appearing in admin search.
                        </p>
                        <div id="erpgulf-gt-regen-result" style="display:none;margin-top:10px;padding:10px;border-radius:4px;font-size:13px;"></div>
                    </td>
                </tr>
                <tr>
                    <th>Sync Existing Translations</th>
                    <td>
                        <button type="button" id="erpgulf-gt-sync-btn" class="button button-secondary">
                            🔄 Sync All Translated Products
                        </button>
                        <p class="description" style="margin-top:6px;">
                            For all already-translated products, copies any missing fields to the English version:
                            <strong>compatibility data, branch stock, images, price, SKU, categories.</strong><br>
                            Does <strong>not</strong> overwrite translated text. No API calls used. Safe to run anytime.
                        </p>
                        <div id="erpgulf-gt-sync-result" style="display:none;margin-top:10px;padding:10px;border-radius:4px;font-size:13px;"></div>
                    </td>
                </tr>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {

            $('#erpgulf-gt-regen-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Regenerating...');
                $('#erpgulf-gt-regen-result').hide();
                $.post(ajaxurl, {
                    action: 'erpgulf_gt_regen_lookup',
                    nonce:  '<?php echo esc_js( wp_create_nonce( "erpgulf_gt_regen" ) ); ?>'
                }, function(res) {
                    btn.prop('disabled', false).text('♻️ Regenerate Lookup Table');
                    if (res.success) {
                        $('#erpgulf-gt-regen-result').show()
                            .css({'background':'#f0fff4','border':'1px solid #68d391','color':'#276749'})
                            .html('✅ ' + res.data.message);
                    } else {
                        $('#erpgulf-gt-regen-result').show()
                            .css({'background':'#fff5f5','border':'1px solid #fc8181','color':'#c53030'})
                            .html('❌ ' + (res.data || 'Failed.'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('♻️ Regenerate Lookup Table');
                    $('#erpgulf-gt-regen-result').show()
                        .css({'background':'#fff5f5','border':'1px solid #fc8181','color':'#c53030'})
                        .html('❌ Network error.');
                });
            });

            $('#erpgulf-gt-sync-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Syncing...');
                $('#erpgulf-gt-sync-result').hide();
                $.post(ajaxurl, {
                    action: 'erpgulf_gt_sync_all',
                    nonce:  '<?php echo esc_js( wp_create_nonce( "erpgulf_gt_sync_all" ) ); ?>'
                }, function(res) {
                    btn.prop('disabled', false).text('🔄 Sync All Translated Products');
                    if (res.success) {
                        var d = res.data;
                        var html = '✅ <strong>' + d.total + '</strong> English product(s) synced.<br>'
                                 + '&nbsp;&nbsp;• Compatibility: ' + d.compatibility + ' updated<br>'
                                 + '&nbsp;&nbsp;• Branch stock: ' + d.branch_stock + ' updated<br>'
                                 + '&nbsp;&nbsp;• Images: ' + d.images + ' updated<br>'
                                 + '&nbsp;&nbsp;• SKU: ' + d.sku + ' updated<br>'
                                 + '&nbsp;&nbsp;• Price: ' + d.price + ' updated<br>'
                                 + '&nbsp;&nbsp;• Categories: ' + d.categories + ' updated';
                        $('#erpgulf-gt-sync-result').show()
                            .css({'background':'#f0fff4','border':'1px solid #68d391','color':'#276749'})
                            .html(html);
                    } else {
                        $('#erpgulf-gt-sync-result').show()
                            .css({'background':'#fff5f5','border':'1px solid #fc8181','color':'#c53030'})
                            .html('❌ ' + (res.data || 'Failed.'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('🔄 Sync All Translated Products');
                    $('#erpgulf-gt-sync-result').show()
                        .css({'background':'#fff5f5','border':'1px solid #fc8181','color':'#c53030'})
                        .html('❌ Network error.');
                });
            });
        });
        </script>

    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// META BOX — product edit page
// ─────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'erpgulf_gt_meta_box',
        '🤖 ERPGulf AI Translate',
        'erpgulf_gt_meta_box_render',
        'product',
        'side',
        'high'
    );
} );

function erpgulf_gt_meta_box_render( $post ) {

    // Only show translator on source language products (Arabic by default)
    $current_lang = apply_filters( 'wpml_element_language_code', null, [
        'element_id'   => $post->ID,
        'element_type' => 'post_product',
    ] );

    $source_lang_code = erpgulf_gt_lang_name_to_code(
        get_option( 'erpgulf_gt_source_lang', 'Arabic' )
    );

    if ( $current_lang && $current_lang !== $source_lang_code ) {
        echo '<p style="font-size:12px;color:#888;font-family:sans-serif;margin:0;">'
           . '⚙️ This is the <strong>' . esc_html( strtoupper( $current_lang ) ) . '</strong> version. '
           . 'Open the <strong>Arabic</strong> product to translate.'
           . '</p>';
        return;
    }

    $registry      = erpgulf_gt_ai_providers();
    $active_key    = erpgulf_gt_active_provider();
    $active_info   = $registry[ $active_key ];
    $api_key_saved = get_option( $active_info['key_option'], '' );
    $target_lang   = get_option( 'erpgulf_gt_target_lang', 'English' );
    $source_lang   = get_option( 'erpgulf_gt_source_lang', 'Arabic' );
    $fields        = get_option( 'erpgulf_gt_fields', [ 'title', 'content', 'excerpt' ] );
    $wpml_active   = defined( 'ICL_LANGUAGE_CODE' );
    $lang_code      = erpgulf_gt_lang_name_to_code( $target_lang );
    $en_post_id_raw = $wpml_active
        ? apply_filters( 'wpml_object_id', $post->ID, 'product', false, $lang_code )
        : null;

    // Detect if English post is in trash
    $en_post_status = $en_post_id_raw ? get_post_status( $en_post_id_raw ) : false;
    $en_in_trash    = ( $en_post_status === 'trash' );

    // Only use en_post_id for "exists" logic if it's actually published/live
    $en_post_id = ( $en_post_id_raw && ! in_array( $en_post_status, [ false, 'trash', 'auto-draft' ], true ) )
        ? $en_post_id_raw
        : null;
    ?>

    <div id="erpgulf-gt-box" style="font-family:sans-serif;">

        <p style="font-size:12px;color:#555;margin:0 0 6px;">
            <strong>Provider:</strong>
            <span style="background:#007cba;color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;">
                <?php echo esc_html( $active_info['label'] ); ?>
            </span>
        </p>

        <p style="font-size:12px;color:#555;margin:0 0 6px;">
            <strong>Direction:</strong>
            <?php echo esc_html( $source_lang ); ?> → <?php echo esc_html( $target_lang ); ?>
        </p>

        <p style="font-size:12px;color:#555;margin:0 0 10px;">
            <strong>Fields:</strong>
            <?php
            $field_labels = array_map( function($f) {
                return ucwords( str_replace( [ 'meta:', 'repeater:', '_', '-' ], [ '', '', ' ', ' ' ], $f ) );
            }, $fields );
            echo esc_html( implode( ', ', $field_labels ) );
            ?>
        </p>

        <?php if ( ! $api_key_saved ): ?>
            <p style="color:#cc1818;font-size:12px;background:#fff5f5;padding:8px;border-radius:4px;">
                ⚠️ <?php echo esc_html( $active_info['label'] ); ?> key not set.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=erpgulf-woo-ai-translator' ) ); ?>">Settings →</a>
            </p>
        <?php else: ?>

            <?php if ( $en_in_trash && $en_post_id_raw ): ?>
                {{!-- English post is in trash — show restore/delete options --}}
                <div style="background:#fff8e1;border:1px solid #f0ad00;border-radius:4px;padding:10px;margin-bottom:10px;">
                    <p style="font-size:12px;color:#7a5c00;margin:0 0 8px;">
                        🗑️ <?php echo esc_html( $target_lang ); ?> version is in Trash
                    </p>
                    <div style="display:flex;gap:6px;">
                        <button type="button"
                                id="erpgulf-gt-restore-btn"
                                class="button button-small"
                                style="font-size:11px;flex:1;"
                                data-post-id="<?php echo esc_attr( $en_post_id_raw ); ?>"
                                data-action="restore"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_trash_action' ) ); ?>">
                            ♻️ Restore
                        </button>
                        <button type="button"
                                id="erpgulf-gt-delete-btn"
                                class="button button-small"
                                style="font-size:11px;flex:1;color:#cc1818;border-color:#cc1818;"
                                data-post-id="<?php echo esc_attr( $en_post_id_raw ); ?>"
                                data-action="delete"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_trash_action' ) ); ?>">
                            🗑️ Delete
                        </button>
                    </div>
                    <div id="erpgulf-gt-trash-result" style="display:none;margin-top:8px;font-size:11px;"></div>
                </div>
            <?php elseif ( $wpml_active && $en_post_id && $en_post_id !== $post->ID ): ?>
                <p style="font-size:12px;color:green;margin:0 0 10px;">
                    ✅ <?php echo esc_html( $target_lang ); ?> version exists
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( get_edit_post_link( $en_post_id ) ); ?>"
                       target="_blank"
                       style="font-size:12px;color:#007cba;">
                        View English →
                    </a>
                </p>
            <?php elseif ( $wpml_active ): ?>
                <p style="font-size:12px;color:#f08c00;margin:0 0 10px;">
                    ⚠️ No <?php echo esc_html( $target_lang ); ?> version yet — will be created.
                </p>
            <?php else: ?>
                <p style="font-size:12px;color:#cc1818;margin:0 0 10px;">
                    ⚠️ WPML not active.
                </p>
            <?php endif; ?>

            <button type="button"
                    id="erpgulf-gt-btn"
                    class="button button-primary"
                    style="width:100%;"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_translate' ) ); ?>">
                🤖 Translate to <?php echo esc_html( $target_lang ); ?>
            </button>

            <div id="erpgulf-gt-progress"
                 style="display:none;margin-top:10px;font-size:12px;color:#555;padding:8px;background:#f9f9f9;border-radius:4px;">
                <span id="erpgulf-gt-status">Connecting...</span>
            </div>

            <div id="erpgulf-gt-result" style="display:none;margin-top:10px;font-size:12px;"></div>

        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {

        var targetLang = '<?php echo esc_js( $target_lang ); ?>';

        // ── Restore / Delete trashed English post ────────────────
        $('#erpgulf-gt-restore-btn, #erpgulf-gt-delete-btn').on('click', function() {
            var btn    = $(this);
            var action = btn.data('action');
            var postId = btn.data('post-id');
            var nonce  = btn.data('nonce');
            var label  = action === 'restore' ? 'Restoring...' : 'Deleting...';

            if ( action === 'delete' && ! confirm('Permanently delete this English product? This cannot be undone.') ) return;

            btn.prop('disabled', true).text(label);
            $('#erpgulf-gt-trash-result').hide();

            $.post(ajaxurl, {
                action:   'erpgulf_gt_trash_action',
                post_id:  postId,
                do:       action,
                nonce:    nonce
            }, function(res) {
                btn.prop('disabled', false);
                if (res.success) {
                    $('#erpgulf-gt-trash-result')
                        .show()
                        .css('color', action === 'restore' ? 'green' : '#cc1818')
                        .text(res.data.message);
                    // Reload page after 1.2s to reflect new state
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    btn.text(action === 'restore' ? '♻️ Restore' : '🗑️ Delete');
                    $('#erpgulf-gt-trash-result').show().css('color','red').text(res.data || 'Failed.');
                }
            }).fail(function() {
                btn.prop('disabled', false).text(action === 'restore' ? '♻️ Restore' : '🗑️ Delete');
                $('#erpgulf-gt-trash-result').show().css('color','red').text('Network error.');
            });
        });

        $('#erpgulf-gt-btn').on('click', function() {

            var btn    = $(this);
            var postId = btn.data('post-id');
            var nonce  = btn.data('nonce');

            btn.prop('disabled', true).text('Translating...');
            $('#erpgulf-gt-progress').show();
            $('#erpgulf-gt-result').hide();

            var steps = [
                'Connecting to AI...',
                'Reading source content...',
                'Translating with AI...',
                'Processing response...',
                'Saving to WPML...'
            ];
            var step = 0;
            var ticker = setInterval(function() {
                step++;
                if (step < steps.length) {
                    $('#erpgulf-gt-status').text(steps[step]);
                }
            }, 1400);

            $.post(ajaxurl, {
                action:  'erpgulf_gt_translate',
                post_id: postId,
                nonce:   nonce
            }, function(res) {

                clearInterval(ticker);
                $('#erpgulf-gt-progress').hide();
                btn.prop('disabled', false).text('🤖 Translate to ' + targetLang);

                if (res.success) {
                    var html = '<div style="background:#f0fff4;border:1px solid #68d391;border-radius:4px;padding:10px;">'
                             + '<strong style="color:#276749;">✅ Translation saved!</strong>'
                             + '<ul style="margin:6px 0 0;padding-left:16px;">'
                             + (res.data.translated_fields || []).map(function(f) {
                                 return '<li>' + f + '</li>';
                               }).join('')
                             + '</ul>';

                    if (res.data.en_edit_url) {
                        html += '<a href="' + res.data.en_edit_url + '" target="_blank" style="display:block;margin-top:8px;">View English product →</a>';
                    }
                    if (res.data.warnings && res.data.warnings.length) {
                        html += '<p style="color:#f08c00;margin:6px 0 0;">⚠️ ' + res.data.warnings.join('<br>') + '</p>';
                    }
                    html += '</div>';
                    $('#erpgulf-gt-result').show().html(html);

                } else {
                    $('#erpgulf-gt-result').show().html(
                        '<div style="background:#fff5f5;border:1px solid #fc8181;border-radius:4px;padding:10px;">'
                        + '<strong style="color:#c53030;">❌ ' + (res.data || 'Translation failed.') + '</strong>'
                        + '</div>'
                    );
                }

            }).fail(function() {
                clearInterval(ticker);
                $('#erpgulf-gt-progress').hide();
                btn.prop('disabled', false).text('🤖 Translate to ' + targetLang);
                $('#erpgulf-gt-result').show().html(
                    '<div style="background:#fff5f5;border:1px solid #fc8181;border-radius:4px;padding:10px;">'
                    + '<strong style="color:#c53030;">❌ Network error. Please try again.</strong>'
                    + '</div>'
                );
            });
        });
    });
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// AJAX — Empty all trashed products
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_empty_trash', function () {

    if ( ! check_ajax_referer( 'erpgulf_gt_empty_trash', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'delete_products' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $trashed = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'trash',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $count = 0;
    foreach ( $trashed as $id ) {
        if ( wp_delete_post( $id, true ) ) $count++;
    }

    wp_send_json_success( [ 'message' => "{$count} trashed product(s) permanently deleted." ] );
} );

// ─────────────────────────────────────────────────────────────────
// AJAX — Fix stale WPML translation links
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_fix_wpml', function () {

    if ( ! check_ajax_referer( 'erpgulf_gt_fix_wpml', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    global $wpdb;

    // Delete WPML translation records pointing to posts that no longer exist
    $deleted = $wpdb->query(
        "DELETE icl FROM {$wpdb->prefix}icl_translations icl
         LEFT JOIN {$wpdb->posts} p ON p.ID = icl.element_id
         WHERE icl.element_type = 'post_product'
           AND p.ID IS NULL"
    );

    // Also remove records pointing to trashed posts
    $trashed = $wpdb->query(
        "DELETE icl FROM {$wpdb->prefix}icl_translations icl
         INNER JOIN {$wpdb->posts} p ON p.ID = icl.element_id
         WHERE icl.element_type = 'post_product'
           AND p.post_status = 'trash'"
    );

    $total = intval( $deleted ) + intval( $trashed );
    wp_send_json_success( [ 'message' => "{$total} stale WPML record(s) removed." ] );
} );

// ─────────────────────────────────────────────────────────────────
// AJAX — Translation status summary
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_status', function () {

    if ( ! check_ajax_referer( 'erpgulf_gt_status', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }

    global $wpdb;

    $source_lang = erpgulf_gt_lang_name_to_code( get_option( 'erpgulf_gt_source_lang', 'Arabic' ) );
    $target_lang = erpgulf_gt_lang_name_to_code( get_option( 'erpgulf_gt_target_lang', 'English' ) );

    // Total Arabic products
    $total_arabic = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->prefix}icl_translations icl ON icl.element_id = p.ID
         WHERE p.post_type = 'product' AND p.post_status = 'publish'
           AND icl.element_type = 'post_product' AND icl.language_code = %s",
        $source_lang
    ) );

    // Translated (have live English version)
    $translated = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT ar.element_id)
         FROM {$wpdb->prefix}icl_translations ar
         INNER JOIN {$wpdb->prefix}icl_translations en ON en.trid = ar.trid AND en.language_code = %s
         INNER JOIN {$wpdb->posts} enp ON enp.ID = en.element_id AND enp.post_status != 'trash'
         INNER JOIN {$wpdb->posts} p ON p.ID = ar.element_id AND p.post_status = 'publish'
         WHERE ar.language_code = %s AND ar.element_type = 'post_product'",
        $target_lang, $source_lang
    ) );

    // Trashed English versions
    $trashed = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT en.element_id)
         FROM {$wpdb->prefix}icl_translations en
         INNER JOIN {$wpdb->posts} p ON p.ID = en.element_id AND p.post_status = 'trash'
         WHERE en.language_code = %s AND en.element_type = 'post_product'",
        $target_lang
    ) );

    wp_send_json_success( [
        'translated'   => $translated,
        'untranslated' => $total_arabic - $translated,
        'trashed'      => $trashed,
        'total'        => $total_arabic,
    ] );
} );

// ─────────────────────────────────────────────────────────────────
// AJAX — Restore or permanently delete trashed English post
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_trash_action', 'erpgulf_gt_handle_trash_action' );

function erpgulf_gt_handle_trash_action() {

    if ( ! check_ajax_referer( 'erpgulf_gt_trash_action', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'delete_products' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $post_id = intval( $_POST['post_id'] ?? 0 );
    $do      = sanitize_key( $_POST['do'] ?? '' );

    if ( ! $post_id || ! in_array( $do, [ 'restore', 'delete' ], true ) ) {
        wp_send_json_error( 'Invalid request.' );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_send_json_error( 'Post not found.' );
    }

    if ( $do === 'restore' ) {
        $result = wp_untrash_post( $post_id );
        if ( $result ) {
            wp_send_json_success( [ 'message' => '✅ Restored successfully. Reloading...' ] );
        } else {
            wp_send_json_error( 'Failed to restore post.' );
        }
    }

    if ( $do === 'delete' ) {
        $result = wp_delete_post( $post_id, true ); // true = force delete
        if ( $result ) {
            wp_send_json_success( [ 'message' => '🗑️ Deleted permanently. Reloading...' ] );
        } else {
            wp_send_json_error( 'Failed to delete post.' );
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// AJAX — TRANSLATE HANDLER
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_translate', 'erpgulf_gt_handle_translate' );

function erpgulf_gt_handle_translate() {

    if ( ! check_ajax_referer( 'erpgulf_gt_translate', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $post_id = intval( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) wp_send_json_error( 'Invalid product ID.' );

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'product' ) {
        wp_send_json_error( 'Product not found.' );
    }

    $registry     = erpgulf_gt_ai_providers();
    $active_key   = erpgulf_gt_active_provider();
    $active_info  = $registry[ $active_key ];
    $translate_fn = 'erpgulf_gt_translate_' . $active_key;

    if ( ! function_exists( $translate_fn ) ) {
        wp_send_json_error( "AI provider '{$active_key}' not found." );
    }

    if ( empty( get_option( $active_info['key_option'], '' ) ) ) {
        wp_send_json_error( $active_info['label'] . ' API key is not set. Go to ERPGulf AI Translate → Settings.' );
    }

    $settings = [
        'gemini_api_key' => get_option( 'erpgulf_gt_gemini_api_key', '' ),
        'gemini_model'   => get_option( 'erpgulf_gt_gemini_model',   'gemini-2.0-flash' ),
        'openai_api_key' => get_option( 'erpgulf_gt_openai_api_key', '' ),
        'openai_model'   => get_option( 'erpgulf_gt_openai_model',   'gpt-4o-mini' ),
        'claude_api_key' => get_option( 'erpgulf_gt_claude_api_key', '' ),
        'claude_model'   => get_option( 'erpgulf_gt_claude_model',   'claude-haiku-4-5-20251001' ),
    ];

    $source_lang = get_option( 'erpgulf_gt_source_lang', 'Arabic' );
    $target_lang = get_option( 'erpgulf_gt_target_lang', 'English' );
    $configured  = get_option( 'erpgulf_gt_fields', [ 'title', 'content', 'excerpt' ] );
    $fields      = (array) apply_filters( 'erpgulf_gt_fields', $configured, $post_id );

    do_action( 'erpgulf_gt_before_translate', $post_id );

    $translations      = [];
    $translated_fields = [];
    $errors            = [];

    foreach ( $fields as $field ) {

        if ( str_starts_with( $field, 'repeater:' ) ) {
            $repeater_key    = preg_replace( '/^repeater:/', '', $field );
            $repeater_result = erpgulf_gt_translate_repeater(
                $post_id, $repeater_key, $translate_fn, $settings, $source_lang, $target_lang
            );
            if ( is_wp_error( $repeater_result ) ) {
                $errors[] = $repeater_key . ': ' . $repeater_result->get_error_message();
            } else {
                $translations[ $field ] = $repeater_result;
                $translated_fields[]    = ucwords( str_replace( '_', ' ', $repeater_key ) ) . ' (repeater) ✓';
            }
            continue;
        }

        if ( $field === 'title' ) {
            $source_text = $post->post_title;
        } elseif ( $field === 'content' ) {
            $source_text = $post->post_content;
        } elseif ( $field === 'excerpt' ) {
            $source_text = $post->post_excerpt;
        } else {
            $meta_key    = preg_replace( '/^meta:/', '', $field );
            $source_text = (string) get_post_meta( $post_id, $meta_key, true );
        }

        if ( empty( trim( (string) $source_text ) ) ) continue;

        $default_prompt = "Translate the following {$source_lang} product text to {$target_lang}. "
            . "Return only the translated text. No explanation. No quotes. No preamble. "
            . "Preserve any HTML tags exactly as they are.\n\n{$source_text}";

        $prompt  = (string) apply_filters( 'erpgulf_gt_prompt', $default_prompt, $field, $source_text, $post_id );
        $result  = $translate_fn( $prompt, $settings );

        if ( is_wp_error( $result ) ) {
            $errors[] = $field . ': ' . $result->get_error_message();
            do_action( 'erpgulf_gt_translation_failed', $post_id, $field, $result->get_error_message() );
            continue;
        }

        $translated             = (string) apply_filters( 'erpgulf_gt_translated_text', $result, $source_text, $field, $post_id );
        $translations[ $field ] = $translated;
        $translated_fields[]    = ucwords( str_replace( [ 'meta:', '_', '-' ], [ '', ' ', ' ' ], $field ) ) . ' ✓';
    }

    if ( empty( $translations ) ) {
        wp_send_json_error( ! empty( $errors )
            ? implode( ' | ', $errors )
            : 'No fields translated. Check source fields are not empty.' );
    }

    $save_result = erpgulf_gt_save_to_wpml( $post_id, $translations, $target_lang, $translate_fn, $settings );

    if ( is_wp_error( $save_result ) ) {
        wp_send_json_error( $save_result->get_error_message() );
    }

    $en_post_id = $save_result;
    do_action( 'erpgulf_gt_after_translate', $post_id, $translations );

    wp_send_json_success( [
        'translated_fields' => $translated_fields,
        'provider_used'     => $active_info['label'],
        'en_post_id'        => $en_post_id,
        'en_edit_url'       => $en_post_id ? get_edit_post_link( $en_post_id ) : null,
        'en_title'          => $en_post_id ? get_the_title( $en_post_id ) : ( $translations['title'] ?? '' ),
        'warnings'          => $errors,
    ] );
}

// ─────────────────────────────────────────────────────────────────
// SAVE TO WPML ENGLISH VERSION
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_save_to_wpml( int $ar_post_id, array $translations, string $target_lang, callable $translate_fn = null, array $settings = [] ): int|WP_Error {

    if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
        return new WP_Error( 'no_wpml', 'WPML is not active.' );
    }

    $lang_code  = erpgulf_gt_lang_name_to_code( $target_lang );
    $en_post_id = apply_filters( 'wpml_object_id', $ar_post_id, 'product', false, $lang_code );

    // Verify the post actually exists — WPML may still reference a deleted post
    if ( $en_post_id && in_array( get_post_status( $en_post_id ), [ false, 'trash', 'auto-draft' ], true ) ) {
        $en_post_id = null;
    }

    if ( $en_post_id && $en_post_id !== $ar_post_id ) {

        $update_args = [ 'ID' => $en_post_id ];
        if ( isset( $translations['title'] ) )   $update_args['post_title']   = $translations['title'];
        if ( isset( $translations['content'] ) ) $update_args['post_content'] = $translations['content'];
        if ( isset( $translations['excerpt'] ) ) $update_args['post_excerpt'] = $translations['excerpt'];

        if ( count( $update_args ) > 1 ) {
            $result = wp_update_post( $update_args, true );
            if ( is_wp_error( $result ) ) return $result;
        }

        foreach ( $translations as $field => $value ) {
            if ( in_array( $field, [ 'title', 'content', 'excerpt' ], true ) ) continue;
            if ( str_starts_with( $field, 'repeater:' ) ) continue;
            $meta_key = preg_replace( '/^meta:/', '', $field );
            update_post_meta( $en_post_id, $meta_key, $value );
            if ( function_exists( 'get_field_object' ) ) {
                $field_obj = get_field_object( $meta_key, $ar_post_id );
                if ( $field_obj && ! empty( $field_obj['key'] ) ) {
                    update_post_meta( $en_post_id, '_' . $meta_key, $field_obj['key'] );
                }
            }
        }

        foreach ( $translations as $field => $value ) {
            if ( ! str_starts_with( $field, 'repeater:' ) ) continue;
            $repeater_key = preg_replace( '/^repeater:/', '', $field );
            erpgulf_gt_save_repeater( $ar_post_id, $en_post_id, $repeater_key, $value );
        }

        erpgulf_gt_sync_sku( $ar_post_id, $en_post_id );
        erpgulf_gt_sync_woo_fields( $ar_post_id, $en_post_id, false );
        erpgulf_gt_sync_terms( $ar_post_id, $en_post_id, $lang_code, $translate_fn, $settings );

        return $en_post_id;
    }

    $ar_post = get_post( $ar_post_id );

    do_action( 'wpml_switch_language', $lang_code );

    $new_post_id = wp_insert_post( [
        'post_type'    => 'product',
        'post_status'  => $ar_post->post_status,
        'post_author'  => $ar_post->post_author,
        'post_title'   => $translations['title']   ?? $ar_post->post_title,
        'post_content' => $translations['content'] ?? $ar_post->post_content,
        'post_excerpt' => $translations['excerpt'] ?? $ar_post->post_excerpt,
    ], true );

    do_action( 'wpml_switch_language', ICL_LANGUAGE_CODE );

    if ( is_wp_error( $new_post_id ) ) return $new_post_id;

    foreach ( $translations as $field => $value ) {
        if ( in_array( $field, [ 'title', 'content', 'excerpt' ], true ) ) continue;
        if ( str_starts_with( $field, 'repeater:' ) ) continue;
        $meta_key = preg_replace( '/^meta:/', '', $field );
        update_post_meta( $new_post_id, $meta_key, $value );
        if ( function_exists( 'get_field_object' ) ) {
            $field_obj = get_field_object( $meta_key, $ar_post_id );
            if ( $field_obj && ! empty( $field_obj['key'] ) ) {
                update_post_meta( $new_post_id, '_' . $meta_key, $field_obj['key'] );
            }
        }
    }

    foreach ( $translations as $field => $value ) {
        if ( ! str_starts_with( $field, 'repeater:' ) ) continue;
        $repeater_key = preg_replace( '/^repeater:/', '', $field );
        erpgulf_gt_save_repeater( $ar_post_id, $new_post_id, $repeater_key, $value );
    }

    erpgulf_gt_sync_sku( $ar_post_id, $new_post_id );
    erpgulf_gt_sync_woo_fields( $ar_post_id, $new_post_id, true );
    erpgulf_gt_sync_terms( $ar_post_id, $new_post_id, $lang_code, $translate_fn, $settings );

    $trid = apply_filters( 'wpml_element_trid', null, $ar_post_id, 'post_product' );

    do_action( 'wpml_set_element_language_details', [
        'element_id'           => $new_post_id,
        'element_type'         => 'post_product',
        'trid'                 => $trid,
        'language_code'        => $lang_code,
        'source_language_code' => ICL_LANGUAGE_CODE,
    ] );

    return $new_post_id;
}

// ─────────────────────────────────────────────────────────────────
// HELPER — translate an ACF repeater field
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_translate_repeater(
    int $post_id,
    string $repeater_key,
    callable $translate_fn,
    array $settings,
    string $source_lang,
    string $target_lang
): array|WP_Error {

    $row_count = (int) get_post_meta( $post_id, $repeater_key, true );
    if ( $row_count < 1 ) {
        return new WP_Error( 'repeater_empty', "Repeater {$repeater_key} has no rows." );
    }

    $translated_rows = [];

    for ( $i = 0; $i < $row_count; $i++ ) {

        global $wpdb;
        $sub_keys = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d
               AND meta_key LIKE %s
               AND meta_key NOT LIKE '\_%'
             ORDER BY meta_key ASC",
            $post_id,
            $wpdb->esc_like( $repeater_key . '_' . $i . '_' ) . '%'
        ) );

        $row = [];

        foreach ( $sub_keys as $sub ) {
            $sub_field = str_replace( $repeater_key . '_' . $i . '_', '', $sub->meta_key );
            $value     = $sub->meta_value;

            $skip_translate = (
                empty( trim( $value ) )
                || is_numeric( $value )
                || preg_match( '/^[\d,\s\-\/]+$/', $value )
                || in_array( $sub_field, [ 'years', 'year', 'engine_size', 'size', 'id', 'model' ], true )
            );

            if ( $skip_translate ) {
                $row[ $sub_field ] = $value;
                continue;
            }

            $prompt = "Translate the following {$source_lang} text to {$target_lang}. "
                    . "Return only the translated text. No explanation.\n\n{$value}";

            $result = $translate_fn( $prompt, $settings );
            $row[ $sub_field ] = is_wp_error( $result ) ? $value : trim( $result );
        }

        $translated_rows[ $i ] = $row;
    }

    return $translated_rows;
}

// ─────────────────────────────────────────────────────────────────
// HELPER — save translated repeater rows to English post
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_save_repeater( int $from_id, int $to_id, string $repeater_key, array $translated_rows ): void {

    $row_count = count( $translated_rows );
    update_post_meta( $to_id, $repeater_key, $row_count );

    $ref_value = get_post_meta( $from_id, '_' . $repeater_key, true );
    if ( $ref_value ) update_post_meta( $to_id, '_' . $repeater_key, $ref_value );

    foreach ( $translated_rows as $i => $row ) {
        foreach ( $row as $sub_field => $value ) {
            $meta_key = $repeater_key . '_' . $i . '_' . $sub_field;
            update_post_meta( $to_id, $meta_key, $value );
            $sub_ref = get_post_meta( $from_id, '_' . $meta_key, true );
            if ( $sub_ref ) update_post_meta( $to_id, '_' . $meta_key, $sub_ref );
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// HELPER — copy SKU from Arabic to English post
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_sync_sku( int $from_id, int $to_id ): void {
    $sku = get_post_meta( $from_id, '_sku', true );
    if ( ! empty( $sku ) ) {
        update_post_meta( $to_id, '_sku', $sku );
    }
    $product = wc_get_product( $to_id );
    if ( $product ) $product->save();
}

// ─────────────────────────────────────────────────────────────────
// HELPER — copy WooCommerce fields (price, stock, images etc.)
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_sync_woo_fields( int $from_id, int $to_id, bool $is_create = true ): void {

    $always_copy = [
        '_thumbnail_id', '_product_image_gallery',
        '_virtual', '_downloadable', '_sold_individually', '_featured', '_visibility',
    ];

    $create_only = [
        '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to',
        '_stock', '_stock_status', '_manage_stock', '_backorders', '_low_stock_amount', '_stock_reserved_quantity',
        '_weight', '_length', '_width', '_height',
        '_shipping_class_id', '_tax_status', '_tax_class',
        'woosb_ids', 'bundle_product_items',
    ];

    $keys_to_copy = $is_create ? array_merge( $always_copy, $create_only ) : $always_copy;

    foreach ( $keys_to_copy as $key ) {
        $value = get_post_meta( $from_id, $key, true );
        if ( $value !== '' && $value !== false ) {
            update_post_meta( $to_id, $key, $value );
        }
    }

    erpgulf_gt_copy_branch_stock( $from_id, $to_id );
}

// ─────────────────────────────────────────────────────────────────
// HELPER — copy branch stock repeater rows
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_copy_branch_stock( int $from_id, int $to_id ): void {

    $branch_count = get_post_meta( $from_id, 'branch_stock', true );
    if ( $branch_count === '' || $branch_count === false ) return;

    update_post_meta( $to_id, 'branch_stock', $branch_count );

    $ref = get_post_meta( $from_id, '_branch_stock', true );
    if ( $ref ) update_post_meta( $to_id, '_branch_stock', $ref );

    for ( $i = 0; $i < (int) $branch_count; $i++ ) {
        foreach ( [ 'branch', 'stock_qty' ] as $sub ) {
            $key   = "branch_stock_{$i}_{$sub}";
            $value = get_post_meta( $from_id, $key, true );
            if ( $value !== '' ) update_post_meta( $to_id, $key, $value );
            $sub_ref = get_post_meta( $from_id, '_' . $key, true );
            if ( $sub_ref ) update_post_meta( $to_id, '_' . $key, $sub_ref );
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// AJAX — Sync all existing translated products
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_sync_all', 'erpgulf_gt_handle_sync_all' );

function erpgulf_gt_handle_sync_all() {

    if ( ! check_ajax_referer( 'erpgulf_gt_sync_all', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    global $wpdb;

    $target_lang_code = erpgulf_gt_lang_name_to_code( get_option( 'erpgulf_gt_target_lang', 'English' ) );
    $source_lang_code = erpgulf_gt_lang_name_to_code( get_option( 'erpgulf_gt_source_lang', 'Arabic' ) );

    $pairs = $wpdb->get_results( $wpdb->prepare(
        "SELECT ar.element_id as ar_id, en.element_id as en_id
         FROM {$wpdb->prefix}icl_translations ar
         JOIN {$wpdb->prefix}icl_translations en ON en.trid = ar.trid AND en.language_code = %s
         JOIN {$wpdb->posts} p ON p.ID = ar.element_id AND p.post_type = 'product'
         WHERE ar.language_code = %s AND ar.element_type = 'post_product'
         LIMIT 5000",
        $target_lang_code, $source_lang_code
    ) );

    if ( empty( $pairs ) ) {
        wp_send_json_success( [ 'total'=>0,'compatibility'=>0,'branch_stock'=>0,'images'=>0,'sku'=>0,'price'=>0,'categories'=>0 ] );
    }

    $counts = [ 'total'=>0,'compatibility'=>0,'branch_stock'=>0,'images'=>0,'sku'=>0,'price'=>0,'categories'=>0 ];

    foreach ( $pairs as $pair ) {

        $ar_id = (int) $pair->ar_id;
        $en_id = (int) $pair->en_id;
        $counts['total']++;

        $ar_compat = get_post_meta( $ar_id, 'add_compactable_details', true );
        $en_compat = get_post_meta( $en_id, 'add_compactable_details', true );
        if ( $ar_compat !== '' && $en_compat === '' ) {
            $row_count = (int) $ar_compat;
            update_post_meta( $en_id, 'add_compactable_details', $row_count );
            $ref = get_post_meta( $ar_id, '_add_compactable_details', true );
            if ( $ref ) update_post_meta( $en_id, '_add_compactable_details', $ref );
            for ( $i = 0; $i < $row_count; $i++ ) {
                foreach ( [ 'brand', 'model', 'variant', 'years', 'engine_size' ] as $sub ) {
                    $key   = "add_compactable_details_{$i}_{$sub}";
                    $value = get_post_meta( $ar_id, $key, true );
                    if ( $value !== '' ) update_post_meta( $en_id, $key, $value );
                    $sub_ref = get_post_meta( $ar_id, '_' . $key, true );
                    if ( $sub_ref ) update_post_meta( $en_id, '_' . $key, $sub_ref );
                }
            }
            $counts['compatibility']++;
        }

        $ar_bs = get_post_meta( $ar_id, 'branch_stock', true );
        $en_bs = get_post_meta( $en_id, 'branch_stock', true );
        if ( $ar_bs !== '' && $en_bs === '' ) {
            erpgulf_gt_copy_branch_stock( $ar_id, $en_id );
            $counts['branch_stock']++;
        }

        $ar_img = get_post_meta( $ar_id, '_thumbnail_id', true );
        $en_img = get_post_meta( $en_id, '_thumbnail_id', true );
        if ( $ar_img && ! $en_img ) {
            update_post_meta( $en_id, '_thumbnail_id', $ar_img );
            $gallery = get_post_meta( $ar_id, '_product_image_gallery', true );
            if ( $gallery ) update_post_meta( $en_id, '_product_image_gallery', $gallery );
            $counts['images']++;
        }

        $ar_sku = get_post_meta( $ar_id, '_sku', true );
        $en_sku = get_post_meta( $en_id, '_sku', true );
        if ( $ar_sku && ! $en_sku ) {
            update_post_meta( $en_id, '_sku', $ar_sku );
            $product = wc_get_product( $en_id );
            if ( $product ) $product->save();
            $counts['sku']++;
        }

        $ar_price = get_post_meta( $ar_id, '_regular_price', true );
        $en_price = get_post_meta( $en_id, '_regular_price', true );
        if ( $ar_price !== '' && $en_price === '' ) {
            foreach ( [ '_price', '_regular_price', '_sale_price' ] as $pk ) {
                $v = get_post_meta( $ar_id, $pk, true );
                if ( $v !== '' ) update_post_meta( $en_id, $pk, $v );
            }
            $counts['price']++;
        }

        $en_cats = wp_get_post_terms( $en_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( empty( $en_cats ) || is_wp_error( $en_cats ) ) {
            foreach ( [ 'product_cat', 'offer_category' ] as $tax ) {
                $ar_terms = wp_get_post_terms( $ar_id, $tax );
                if ( is_wp_error( $ar_terms ) || empty( $ar_terms ) ) continue;
                $ids_to_set = [];
                foreach ( $ar_terms as $term ) {
                    $translated   = apply_filters( 'wpml_object_id', $term->term_id, $tax, true, $target_lang_code );
                    $ids_to_set[] = (int) $translated;
                }
                do_action( 'wpml_switch_language', $target_lang_code );
                wp_set_post_terms( $en_id, $ids_to_set, $tax, false );
                do_action( 'wpml_switch_language', $source_lang_code );
            }
            $counts['categories']++;
        }
    }

    wp_send_json_success( $counts );
}

// ─────────────────────────────────────────────────────────────────
// AJAX — Regenerate WooCommerce product lookup table
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_regen_lookup', 'erpgulf_gt_handle_regen_lookup' );

function erpgulf_gt_handle_regen_lookup() {

    if ( ! check_ajax_referer( 'erpgulf_gt_regen', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    global $wpdb;

    $product_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 5000"
    );

    if ( empty( $product_ids ) ) {
        wp_send_json_success( [ 'message' => 'No published products found.' ] );
    }

    $count = 0; $errors = 0;
    foreach ( $product_ids as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) { $errors++; continue; }
        $product->save();
        $count++;
    }

    wp_send_json_success( [
        'message' => "{$count} product(s) updated in lookup table." . ( $errors ? " {$errors} skipped." : '' ),
    ] );
}

// ─────────────────────────────────────────────────────────────────
// HELPER — copy product categories and tags to English post
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_sync_terms( int $from_id, int $to_id, string $lang_code, callable $translate_fn = null, array $settings = [] ): void {

    $taxonomies  = [ 'product_cat', 'product_tag', 'product_type', 'offer_category' ];
    $source_lang = get_option( 'erpgulf_gt_source_lang', 'Arabic' );
    $target_lang = get_option( 'erpgulf_gt_target_lang', 'English' );

    foreach ( $taxonomies as $taxonomy ) {

        $terms = wp_get_post_terms( $from_id, $taxonomy );
        if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

        $ids_to_set = [];

        foreach ( $terms as $term ) {

            $translated_term_id = apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, $lang_code );

            if ( $translated_term_id && $translated_term_id !== $term->term_id ) {
                $ids_to_set[] = (int) $translated_term_id;
            } elseif ( $translate_fn && in_array( $taxonomy, [ 'product_cat', 'offer_category' ], true ) ) {
                $new_term_id = erpgulf_gt_create_english_term( $term, $lang_code, $translate_fn, $settings, $source_lang, $target_lang );
                $ids_to_set[] = $new_term_id ? (int) $new_term_id : (int) $term->term_id;
            } else {
                $ids_to_set[] = (int) $term->term_id;
            }
        }

        if ( empty( $ids_to_set ) ) continue;

        do_action( 'wpml_switch_language', $lang_code );
        wp_set_post_terms( $to_id, $ids_to_set, $taxonomy, false );
        do_action( 'wpml_switch_language', ICL_LANGUAGE_CODE );
    }
}

// ─────────────────────────────────────────────────────────────────
// HELPER — create English version of an Arabic category
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_create_english_term( WP_Term $ar_term, string $lang_code, callable $translate_fn, array $settings, string $source_lang, string $target_lang ): int|false {

    $prompt = "Translate this {$source_lang} product category name to {$target_lang}. "
            . "Return only the translated name. No explanation. No punctuation around it.\n\n"
            . $ar_term->name;

    $result = $translate_fn( $prompt, $settings );
    if ( is_wp_error( $result ) || empty( trim( $result ) ) ) return false;

    $en_name  = trim( $result );
    $taxonomy = $ar_term->taxonomy;

    $existing = get_term_by( 'name', $en_name, $taxonomy );
    if ( $existing && ! is_wp_error( $existing ) ) {
        erpgulf_gt_link_term_to_wpml( $existing->term_id, $ar_term->term_id, $taxonomy, $lang_code );
        return $existing->term_id;
    }

    $parent_id = 0;
    if ( $ar_term->parent ) {
        $en_parent_id = apply_filters( 'wpml_object_id', $ar_term->parent, $taxonomy, false, $lang_code );
        if ( $en_parent_id ) $parent_id = (int) $en_parent_id;
    }

    do_action( 'wpml_switch_language', $lang_code );
    $new_term = wp_insert_term( $en_name, $taxonomy, [ 'parent' => $parent_id, 'slug' => sanitize_title( $en_name ) ] );
    do_action( 'wpml_switch_language', ICL_LANGUAGE_CODE );

    if ( is_wp_error( $new_term ) ) {
        $new_term = wp_insert_term( $en_name, $taxonomy, [ 'parent' => $parent_id, 'slug' => sanitize_title( $en_name ) . '-en' ] );
        if ( is_wp_error( $new_term ) ) return false;
    }

    $new_term_id = $new_term['term_id'];
    erpgulf_gt_link_term_to_wpml( $new_term_id, $ar_term->term_id, $taxonomy, $lang_code );
    return $new_term_id;
}

// ─────────────────────────────────────────────────────────────────
// HELPER — link English term to Arabic term in WPML
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_link_term_to_wpml( int $en_term_id, int $ar_term_id, string $taxonomy, string $lang_code ): void {

    global $wpdb;

    $en_tt_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s",
        $en_term_id, $taxonomy
    ) );
    $ar_tt_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s",
        $ar_term_id, $taxonomy
    ) );

    if ( ! $en_tt_id || ! $ar_tt_id ) return;

    $trid = $wpdb->get_var( $wpdb->prepare(
        "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s",
        $ar_tt_id, 'tax_' . $taxonomy
    ) );
    if ( ! $trid ) return;

    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s",
        $en_tt_id, 'tax_' . $taxonomy
    ) );

    if ( $existing ) {
        $wpdb->update(
            $wpdb->prefix . 'icl_translations',
            [ 'trid' => $trid, 'language_code' => $lang_code, 'source_language_code' => ICL_LANGUAGE_CODE ],
            [ 'translation_id' => $existing ]
        );
    } else {
        $wpdb->insert( $wpdb->prefix . 'icl_translations', [
            'element_type'         => 'tax_' . $taxonomy,
            'element_id'           => $en_tt_id,
            'trid'                 => $trid,
            'language_code'        => $lang_code,
            'source_language_code' => ICL_LANGUAGE_CODE,
        ] );
    }
}

// ─────────────────────────────────────────────────────────────────
// HELPER — language name → WPML code
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_lang_name_to_code( string $name ): string {
    $map = [
        'english'=>'en','arabic'=>'ar','french'=>'fr','german'=>'de','spanish'=>'es',
        'italian'=>'it','turkish'=>'tr','portuguese'=>'pt','urdu'=>'ur','hindi'=>'hi',
        'dutch'=>'nl','russian'=>'ru',
    ];
    return $map[ strtolower( trim( $name ) ) ] ?? strtolower( trim( $name ) );
}

// ─────────────────────────────────────────────────────────────────
// ADMIN NOTICE — missing API key on product page
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_notices', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'product' ) return;
    $registry    = erpgulf_gt_ai_providers();
    $active_key  = erpgulf_gt_active_provider();
    $active_info = $registry[ $active_key ];
    if ( get_option( $active_info['key_option'], '' ) ) return;
    echo '<div class="notice notice-warning is-dismissible"><p>'
       . '<strong>ERPGulf AI Translate:</strong> '
       . esc_html( $active_info['label'] ) . ' API key is not set. '
       . '<a href="' . esc_url( admin_url( 'admin.php?page=erpgulf-woo-ai-translator' ) ) . '">Go to settings →</a>'
       . '</p></div>';
} );

// ─────────────────────────────────────────────────────────────────
// BULK TRANSLATE — Dedicated page (replaces old bulk action)
// Registered as submenu under ERPGulf AI Translate
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_submenu_page(
        'erpgulf-woo-ai-translator',
        'Bulk Translate',
        'Bulk Translate',
        'manage_options',
        'erpgulf-gt-bulk',
        'erpgulf_gt_bulk_page_render'
    );
} );

function erpgulf_gt_bulk_page_render() {

    $registry    = erpgulf_gt_ai_providers();
    $active_key  = erpgulf_gt_active_provider();
    $active_info = $registry[ $active_key ];
    $source_lang = get_option( 'erpgulf_gt_source_lang', 'Arabic' );
    $target_lang = get_option( 'erpgulf_gt_target_lang', 'English' );
    $lang_code   = erpgulf_gt_lang_name_to_code( $source_lang );
    $nonce       = wp_create_nonce( 'erpgulf_gt_translate' );
    $api_key_ok  = ! empty( get_option( $active_info['key_option'], '' ) );

    global $wpdb;

    // Get all Arabic products that do NOT have an English version
    $untranslated = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, p.post_title,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) as sku
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->prefix}icl_translations icl
                 ON icl.element_id = p.ID
                AND icl.element_type = 'post_product'
                AND icl.language_code = %s
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
         WHERE p.post_type = 'product'
           AND p.post_status = 'publish'
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->prefix}icl_translations en
               INNER JOIN {$wpdb->posts} enp ON enp.ID = en.element_id
               WHERE en.trid = icl.trid
                 AND en.language_code = %s
                 AND enp.post_status != 'trash'
           )
         GROUP BY p.ID
         ORDER BY p.post_title ASC",
        $lang_code,
        erpgulf_gt_lang_name_to_code( $target_lang )
    ) );

    $total = count( $untranslated );
    ?>
    <div class="wrap" style="max-width:1000px;">

        {{!-- ── Header ── --}}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
            <div>
                <h1 style="margin:0;">🤖 Bulk Translate</h1>
                <p style="color:#666;margin:4px 0 0;font-size:13px;">
                    Untranslated Arabic products → <?php echo esc_html( $target_lang ); ?> via <?php echo esc_html( $active_info['label'] ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=erpgulf-woo-ai-translator' ) ); ?>"
               class="button">← Settings</a>
        </div>

        <?php if ( ! $api_key_ok ): ?>
            <div style="background:#fff5f5;border:1px solid #fc8181;border-radius:6px;padding:16px;margin-bottom:20px;">
                ⚠️ <strong><?php echo esc_html( $active_info['label'] ); ?> API key is not set.</strong>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=erpgulf-woo-ai-translator' ) ); ?>">Go to Settings →</a>
            </div>
        <?php endif; ?>

        {{!-- ── Stats bar ── --}}
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#007cba;" id="gt-count-total"><?php echo $total; ?></div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Untranslated</div>
            </div>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#276749;" id="gt-count-done">0</div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Translated ✅</div>
            </div>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#c53030;" id="gt-count-failed">0</div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Failed ❌</div>
            </div>
        </div>

        {{!-- ── Progress bar ── --}}
        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <span style="font-size:13px;font-weight:600;" id="gt-progress-label">Ready to start</span>
                <span style="font-size:13px;color:#888;" id="gt-progress-count">0 / <?php echo $total; ?></span>
            </div>
            <div style="background:#eee;border-radius:20px;height:12px;overflow:hidden;margin-bottom:16px;">
                <div id="gt-progress-bar"
                     style="height:100%;width:0;border-radius:20px;background:linear-gradient(90deg,#007cba,#00a0d2);transition:width 0.4s ease;">
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button id="gt-btn-start"
                        class="button button-primary"
                        style="min-width:120px;"
                        <?php echo ! $api_key_ok ? 'disabled' : ''; ?>>
                    ▶ Start
                </button>
                <button id="gt-btn-pause"
                        class="button"
                        style="min-width:120px;display:none;">
                    ⏸ Pause
                </button>
                <button id="gt-btn-resume"
                        class="button button-primary"
                        style="min-width:120px;display:none;">
                    ▶ Resume
                </button>
                <button id="gt-btn-stop"
                        class="button"
                        style="min-width:120px;color:#cc1818;border-color:#cc1818;display:none;">
                    ⏹ Stop
                </button>
            </div>
        </div>

        {{!-- ── Maintenance Tools ── --}}
        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;margin-bottom:20px;">
            <h3 style="margin:0 0 14px;font-size:14px;color:#333;">🔧 Maintenance Tools</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;">

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:4px;">♻️ Regenerate Lookup Table</div>
                    <div style="font-size:11px;color:#888;margin-bottom:10px;">Fixes admin SKU search for all products.</div>
                    <button class="button button-small gt-tool-btn" style="width:100%;"
                            data-tool="regen_lookup"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_regen' ) ); ?>"
                            data-action="erpgulf_gt_regen_lookup">
                        Run
                    </button>
                    <div class="gt-tool-result" style="display:none;font-size:11px;margin-top:8px;"></div>
                </div>

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:4px;">🔄 Sync Translated Products</div>
                    <div style="font-size:11px;color:#888;margin-bottom:10px;">Copies missing fields to English versions.</div>
                    <button class="button button-small gt-tool-btn" style="width:100%;"
                            data-tool="sync_all"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_sync_all' ) ); ?>"
                            data-action="erpgulf_gt_sync_all">
                        Run
                    </button>
                    <div class="gt-tool-result" style="display:none;font-size:11px;margin-top:8px;"></div>
                </div>

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:4px;">🗑️ Empty Trash</div>
                    <div style="font-size:11px;color:#888;margin-bottom:10px;">Permanently deletes all trashed products.</div>
                    <button class="button button-small gt-tool-btn" style="width:100%;color:#cc1818;border-color:#cc1818;"
                            data-tool="empty_trash"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_empty_trash' ) ); ?>"
                            data-action="erpgulf_gt_empty_trash"
                            data-confirm="Permanently delete ALL trashed products? This cannot be undone.">
                        Run
                    </button>
                    <div class="gt-tool-result" style="display:none;font-size:11px;margin-top:8px;"></div>
                </div>

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:4px;">🔗 Fix WPML Links</div>
                    <div style="font-size:11px;color:#888;margin-bottom:10px;">Removes stale WPML records pointing to deleted posts.</div>
                    <button class="button button-small gt-tool-btn" style="width:100%;"
                            data-tool="fix_wpml"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_fix_wpml' ) ); ?>"
                            data-action="erpgulf_gt_fix_wpml">
                        Run
                    </button>
                    <div class="gt-tool-result" style="display:none;font-size:11px;margin-top:8px;"></div>
                </div>

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:4px;">📊 Translation Status</div>
                    <div style="font-size:11px;color:#888;margin-bottom:10px;">Shows translated vs untranslated count.</div>
                    <button class="button button-small gt-tool-btn" style="width:100%;"
                            data-tool="status"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'erpgulf_gt_status' ) ); ?>"
                            data-action="erpgulf_gt_status">
                        Check
                    </button>
                    <div class="gt-tool-result" style="display:none;font-size:11px;margin-top:8px;"></div>
                </div>

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
                    <div style="font-weight:600;font-size:13px;margin-bottom:4px;">🔁 Reload Product List</div>
                    <div style="font-size:11px;color:#888;margin-bottom:10px;">Refresh the untranslated products list.</div>
                    <button class="button button-small" style="width:100%;" onclick="location.reload();">
                        Reload
                    </button>
                </div>

            </div>
        </div>

        {{!-- ── Two column layout ── --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            {{!-- Product list ── --}}
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <h3 style="margin:0;font-size:14px;">
                        📋 Products (<?php echo $total; ?>)
                    </h3>
                    <label style="font-size:12px;color:#666;cursor:pointer;">
                        <input type="checkbox" id="gt-select-all" checked> Select All
                    </label>
                </div>
                <div style="max-height:420px;overflow-y:auto;font-size:12px;" id="gt-product-list">
                    <?php foreach ( $untranslated as $p ): ?>
                        <div class="gt-product-row"
                             data-id="<?php echo esc_attr( $p->ID ); ?>"
                             data-sku="<?php echo esc_attr( $p->sku ?: '—' ); ?>"
                             data-title="<?php echo esc_attr( $p->post_title ); ?>"
                             style="display:flex;align-items:center;gap:8px;padding:7px 4px;border-bottom:1px solid #f0f0f0;">
                            <input type="checkbox" class="gt-product-cb" value="<?php echo esc_attr( $p->ID ); ?>" checked>
                            <span class="gt-row-icon" style="width:16px;flex-shrink:0;"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-weight:500;"
                                     title="<?php echo esc_attr( $p->post_title ); ?>">
                                    <?php echo esc_html( $p->post_title ?: '(no title)' ); ?>
                                </div>
                                <div style="color:#888;font-size:11px;margin-top:1px;">
                                    ID: <?php echo esc_html( $p->ID ); ?>
                                    &nbsp;·&nbsp;
                                    SKU: <?php echo esc_html( $p->sku ?: '—' ); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ( empty( $untranslated ) ): ?>
                        <p style="color:#888;text-align:center;padding:20px 0;">
                            🎉 All products are already translated!
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            {{!-- Live log ── --}}
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;">
                <h3 style="margin:0 0 12px;font-size:14px;">📡 Live Log</h3>
                <div id="gt-log"
                     style="max-height:420px;overflow-y:auto;font-size:12px;font-family:monospace;line-height:1.8;color:#333;">
                    <span style="color:#888;">Waiting to start...</span>
                </div>
            </div>

        </div>

    </div>

    <script>
    jQuery(document).ready(function($) {

        var nonce      = '<?php echo esc_js( $nonce ); ?>';
        var total      = <?php echo $total; ?>;
        var queue      = [];
        var done       = 0;
        var failed     = 0;
        var paused     = false;
        var stopped    = false;
        var processing = false;

        // ── Maintenance tool buttons ──────────────────────────────
        $('.gt-tool-btn').on('click', function() {
            var btn        = $(this);
            var action     = btn.data('action');
            var bnonce     = btn.data('nonce');
            var confirmMsg = btn.data('confirm');
            var $result    = btn.siblings('.gt-tool-result');
            var origText   = btn.text();

            if ( confirmMsg && ! confirm(confirmMsg) ) return;

            btn.prop('disabled', true).text('Running...');
            $result.hide();

            $.post(ajaxurl, { action: action, nonce: bnonce }, function(res) {
                btn.prop('disabled', false).text(origText);
                if ( res.success ) {
                    var msg = '';
                    var d   = res.data;
                    if ( action === 'erpgulf_gt_sync_all' ) {
                        msg = '✅ ' + d.total + ' synced'
                            + ' · Compat: ' + d.compatibility
                            + ' · Stock: ' + d.branch_stock
                            + ' · SKU: ' + d.sku
                            + ' · Price: ' + d.price
                            + ' · Cats: ' + d.categories;
                    } else if ( action === 'erpgulf_gt_status' ) {
                        msg = '📊 ' + d.translated + ' translated · '
                            + d.untranslated + ' untranslated · '
                            + d.trashed + ' in trash';
                    } else {
                        msg = '✅ ' + (d.message || 'Done.');
                    }
                    $result.show().css('color','#276749').text(msg);
                } else {
                    $result.show().css('color','#c53030').text('❌ ' + (res.data || 'Failed.'));
                }
            }).fail(function() {
                btn.prop('disabled', false).text(origText);
                $result.show().css('color','#c53030').text('❌ Network error.');
            });
        });

        // ── Select All toggle ─────────────────────────────────────
        $('#gt-select-all').on('change', function() {
            $('.gt-product-cb').prop('checked', this.checked);
        });

        // ── Build queue from checked products ─────────────────────
        function buildQueue() {
            queue = [];
            $('.gt-product-cb:checked').each(function() {
                queue.push( parseInt( $(this).val() ) );
            });
            return queue.length;
        }

        // ── Log helper ────────────────────────────────────────────
        function log(icon, msg, cls) {
            var $log = $('#gt-log');
            var color = cls === 'ok' ? '#276749' : cls === 'fail' ? '#c53030' : cls === 'active' ? '#007cba' : '#888';
            $log.append(
                '<div style="color:' + color + ';padding:2px 0;">' + icon + ' ' + msg + '</div>'
            );
            $log.scrollTop( $log[0].scrollHeight );
        }

        // ── Update progress UI ────────────────────────────────────
        function updateProgress(current) {
            var total_selected = done + failed + queue.length;
            var pct = total_selected > 0 ? Math.round( (done + failed) / total_selected * 100 ) : 0;
            $('#gt-progress-bar').css('width', pct + '%');
            $('#gt-progress-count').text( (done + failed) + ' / ' + total_selected );
            $('#gt-count-done').text(done);
            $('#gt-count-failed').text(failed);
        }

        // ── Set icon on product row ───────────────────────────────
        function setRowIcon(postId, icon) {
            $('.gt-product-row[data-id="' + postId + '"] .gt-row-icon').text(icon);
        }

        // ── Process next item in queue ────────────────────────────
        function processNext() {
            if ( stopped || paused || queue.length === 0 ) {
                processing = false;
                if ( stopped || queue.length === 0 ) onFinished();
                return;
            }

            processing = true;
            var postId  = queue.shift();
            var $row    = $('.gt-product-row[data-id="' + postId + '"]');
            var arTitle = $row.data('title') || $row.find('div:first').text().trim();
            var sku     = $row.data('sku') || '—';
            var meta    = 'ID:' + postId + ' SKU:' + sku;

            setRowIcon(postId, '🔄');
            $('#gt-progress-label').text('Translating: ' + arTitle.substring(0,50) + '...');
            log('🔄', '[' + meta + '] ' + arTitle.substring(0,50) + '...', 'active');

            $.post(ajaxurl, {
                action:  'erpgulf_gt_translate',
                post_id: postId,
                nonce:   nonce
            }, function(res) {
                if ( res.success ) {
                    done++;
                    setRowIcon(postId, '✅');
                    // Show English title from response if available
                    var enTitle = res.data.en_title || '';
                    var logMsg  = '[' + meta + '] ';
                    if ( enTitle ) {
                        logMsg += enTitle.substring(0,50);
                    } else {
                        logMsg += arTitle.substring(0,50);
                    }
                    log('✅', logMsg, 'ok');
                    $row.find('.gt-product-cb').prop('checked', false);
                } else {
                    failed++;
                    setRowIcon(postId, '❌');
                    log('❌', '[' + meta + '] ' + arTitle.substring(0,40) + ' — ' + (res.data || 'Failed'), 'fail');
                }
                updateProgress();
                setTimeout(processNext, 600);
            }).fail(function() {
                failed++;
                setRowIcon(postId, '❌');
                log('❌', '[' + meta + '] ' + arTitle.substring(0,40) + ' — Network error', 'fail');
                updateProgress();
                setTimeout(processNext, 600);
            });
        }

        // ── On finished ───────────────────────────────────────────
        function onFinished() {
            var msg = stopped ? '⏹ Stopped.' : '✅ All done!';
            $('#gt-progress-label').text(msg + '  ' + done + ' translated, ' + failed + ' failed.');
            log('──', msg + ' ' + done + ' translated · ' + failed + ' failed', 'skip');
            $('#gt-btn-pause').hide();
            $('#gt-btn-resume').hide();
            $('#gt-btn-stop').hide();
            $('#gt-btn-start').show().text('▶ Start Again').prop('disabled', false);
            processing = false;
        }

        // ── Start button ──────────────────────────────────────────
        $('#gt-btn-start').on('click', function() {
            var count = buildQueue();
            if ( count === 0 ) { alert('No products selected.'); return; }

            done = 0; failed = 0; paused = false; stopped = false;
            updateProgress();
            $('#gt-log').html('');
            log('🚀', 'Starting — ' + count + ' products selected', 'active');

            $(this).hide();
            $('#gt-btn-pause').show();
            $('#gt-btn-stop').show();
            processNext();
        });

        // ── Pause button ──────────────────────────────────────────
        $('#gt-btn-pause').on('click', function() {
            paused = true;
            $(this).hide();
            $('#gt-btn-resume').show();
            $('#gt-progress-label').text('⏸ Paused — ' + queue.length + ' remaining');
            log('⏸', 'Paused', 'skip');
        });

        // ── Resume button ─────────────────────────────────────────
        $('#gt-btn-resume').on('click', function() {
            paused = false;
            $(this).hide();
            $('#gt-btn-pause').show();
            log('▶', 'Resumed', 'active');
            processNext();
        });

        // ── Stop button ───────────────────────────────────────────
        $('#gt-btn-stop').on('click', function() {
            if ( ! confirm('Stop the translation? Already translated items are saved.') ) return;
            stopped = true;
            $(this).prop('disabled', true).text('Stopping...');
            $('#gt-btn-pause').hide();
            $('#gt-btn-resume').hide();
            if ( ! processing ) onFinished();
        });

    });
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// BULK ACTION — Show results notice after redirect
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_notices', function () {

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-product' ) return;

    if ( ! empty( $_GET['erpgulf_gt_error'] ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>'
           . '<strong>ERPGulf AI Translate:</strong> '
           . esc_html( urldecode( $_GET['erpgulf_gt_error'] ) )
           . '</p></div>';
    }
} );

// ─────────────────────────────────────────────────────────────────
// AUTO-SYNC — Branch stock changes on Arabic → copy to English
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_branch_stock_sync( $meta_id, $post_id, $meta_key, $meta_value ): void {

    if ( strpos( $meta_key, 'branch_stock' ) === false ) return;

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'product' ) return;

    $current_lang = apply_filters( 'wpml_element_language_code', null, [
        'element_id'   => $post_id,
        'element_type' => 'post_product',
    ] );

    $source_lang_code = erpgulf_gt_lang_name_to_code( get_option( 'erpgulf_gt_source_lang', 'Arabic' ) );
    if ( $current_lang !== $source_lang_code ) return;

    $target_lang_code = erpgulf_gt_lang_name_to_code( get_option( 'erpgulf_gt_target_lang', 'English' ) );
    $en_post_id       = apply_filters( 'wpml_object_id', $post_id, 'product', false, $target_lang_code );
    if ( ! $en_post_id || $en_post_id === $post_id ) return;

    update_post_meta( $en_post_id, $meta_key, $meta_value );
}

add_action( 'updated_post_meta', 'erpgulf_gt_branch_stock_sync', 10, 4 );
add_action( 'added_post_meta',   'erpgulf_gt_branch_stock_sync', 10, 4 );