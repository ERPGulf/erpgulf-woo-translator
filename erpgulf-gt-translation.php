<?php

/**
 * ERPGulf AI Translate — Translation Reconcile + Translate action (REST).
 *
 *   GET  /wp-json/erpgulf/v1/translation-report   report of untranslated products
 *   POST /wp-json/erpgulf/v1/translate-run         translate a batch of flagged products
 *   GET  /wp-json/erpgulf/v1/translate-status      progress of the running translate batch
 *
 * Pairs every Arabic (source) product with its English (/en/) WPML twin, flags what
 * is not translated (Missing English / Name / Description / Compatibility), and can
 * run the plugin's own AI translation on the flagged ones — batched, in the
 * background. Reuses the store's WooCommerce consumer key/secret (HTTP Basic).
 *
 * Self-contained: does not depend on erpgulf-gt-rest.php. Reuses the translator
 * plugin's existing functions (erpgulf_gt_save_to_wpml, providers, etc.).
 */
if (!defined('ABSPATH'))
    exit;

const ERPGULF_GT_TR_OPTION = 'erpgulf_gt_translate_state';
const ERPGULF_GT_TR_HOOK = 'erpgulf_gt_tr_run';
const ERPGULF_GT_TR_MAXAGE = 3600;  // 60 min stale-guard

// ─────────────────────────────────────────────────────────────────
// ROUTES
// ─────────────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('erpgulf/v1', '/translation-report', [
        'methods' => 'GET',
        'permission_callback' => 'erpgulf_gt_tr_can_manage',
        'callback' => 'erpgulf_gt_tr_report',
        'args' => [
            'sku' => ['type' => 'string', 'default' => ''],
            'only_issues' => ['type' => 'boolean', 'default' => false],
            'limit' => ['type' => 'integer', 'default' => 0],
        ],
    ]);

    register_rest_route('erpgulf/v1', '/translate-run', [
        'methods' => 'POST',
        'permission_callback' => 'erpgulf_gt_tr_can_manage',
        'callback' => 'erpgulf_gt_tr_run_start',
        'args' => [
            'sku' => ['type' => 'string', 'default' => ''],
            'limit' => ['type' => 'integer', 'default' => 50],
        ],
    ]);

    register_rest_route('erpgulf/v1', '/translate-status', [
        'methods' => 'GET',
        'permission_callback' => 'erpgulf_gt_tr_can_manage',
        'callback' => 'erpgulf_gt_tr_run_status',
    ]);
});

// ─────────────────────────────────────────────────────────────────
// AUTH — WordPress capability OR WooCommerce consumer key/secret
// ─────────────────────────────────────────────────────────────────

if (!function_exists('erpgulf_gt_tr_can_manage')) {
    function erpgulf_gt_tr_can_manage($request = null): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        list($ck, $cs) = erpgulf_gt_tr_basic_auth_creds();
        if ($ck && $cs && function_exists('wc_api_hash')) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT consumer_secret, permissions, user_id
                 FROM {$wpdb->prefix}woocommerce_api_keys
                 WHERE consumer_key = %s",
                wc_api_hash($ck)
            ));
            if ($row &&
                    hash_equals((string) $row->consumer_secret, (string) $cs) &&
                    in_array($row->permissions, array('read', 'read_write', 'write'), true)) {
                if (!empty($row->user_id)) {
                    wp_set_current_user((int) $row->user_id);
                }
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('erpgulf_gt_tr_basic_auth_creds')) {
    function erpgulf_gt_tr_basic_auth_creds(): array
    {
        $user = '';
        $pass = '';
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            $user = $_SERVER['PHP_AUTH_USER'];
            $pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
        } else {
            $hdr = '';
            if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
                $hdr = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
            if (stripos($hdr, 'Basic ') === 0) {
                $decoded = base64_decode(substr($hdr, 6));
                if ($decoded !== false && strpos($decoded, ':') !== false) {
                    list($user, $pass) = explode(':', $decoded, 2);
                }
            }
        }
        return array($user, $pass);
    }
}

if (!function_exists('erpgulf_gt_tr_has_arabic')) {
    function erpgulf_gt_tr_has_arabic($s): bool
    {
        return $s !== null && $s !== '' && (bool) preg_match('/[\x{0600}-\x{06FF}]/u', (string) $s);
    }
}

// ─────────────────────────────────────────────────────────────────
// CORE — build the reconcile rows (used by the report AND translate)
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_tr_build_rows($sku_filter = '', $only_issues = false, $limit = 0): array
{
    global $wpdb;
    $icl = $wpdb->prefix . 'icl_translations';

    $rows = $wpdb->get_results(
        "SELECT arp.ID AS ar_id, arp.post_title AS ar_name, arp.post_content AS ar_desc,
                enp.ID AS en_id, enp.post_title AS en_name, enp.post_content AS en_desc,
                enp.post_status AS en_status
         FROM {$icl} ar
         JOIN {$wpdb->posts} arp
              ON arp.ID = ar.element_id AND arp.post_type = 'product' AND arp.post_status = 'publish'
         LEFT JOIN {$icl} en
              ON en.trid = ar.trid AND en.language_code = 'en' AND en.element_type = 'post_product'
         LEFT JOIN {$wpdb->posts} enp
              ON enp.ID = en.element_id AND enp.post_type = 'product'
         WHERE ar.language_code = 'ar' AND ar.element_type = 'post_product'
         ORDER BY arp.ID ASC",
        ARRAY_A
    );
    if (!$rows)
        return [];

    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['ar_id'];
        if (!empty($r['en_id']))
            $ids[] = (int) $r['en_id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $in = implode(',', array_map('intval', $ids));

    $sku_map = [];
    $compat_n = [];  // post_id => number of compatibility rows
    $en_cat_bad = [];  // en_id  => true if it has an Arabic-named product_cat
    if ($in !== '') {
        foreach ($wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ($in) AND meta_key = '_sku'", ARRAY_A
        ) as $m) {
            $sku_map[(int) $m['post_id']] = $m['meta_value'];
        }
        foreach ($wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ($in) AND meta_key = 'add_compactable_details'", ARRAY_A
        ) as $m) {
            $compat_n[(int) $m['post_id']] = (int) $m['meta_value'];
        }
        // English products only, for the category-language check.
        $en_in = [];
        foreach ($rows as $rr) {
            if (!empty($rr['en_id']))
                $en_in[] = (int) $rr['en_id'];
        }
        $en_in = implode(',', array_map('intval', array_unique($en_in)));
        if ($en_in !== '') {
            foreach ($wpdb->get_results(
                "SELECT tr.object_id pid, t.name
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
                 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tr.object_id IN ($en_in)", ARRAY_A
            ) as $c) {
                if (erpgulf_gt_tr_has_arabic($c['name']))
                    $en_cat_bad[(int) $c['pid']] = true;
            }
        }
    }

    // Promo tiers per SKU (info only — shared by both twins, not a translation issue).
    $promo_map = [];
    $promo_table = $wpdb->prefix . 'adv_promo_tiers';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $promo_table)) === $promo_table) {
        $skus = array_values(array_unique(array_filter(array_values($sku_map))));
        if ($skus) {
            $ph = implode(',', array_fill(0, count($skus), '%s'));
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT sku, tiers, active FROM {$promo_table} WHERE sku IN ($ph)", $skus
            ), ARRAY_A) as $p) {
                $t = json_decode((string) $p['tiers'], true);
                $n = is_array($t) ? count($t) : 0;
                $promo_map[$p['sku']] = (int) $p['active'] ? ($n . ' tier' . ($n == 1 ? '' : 's')) : ($n ? 'inactive' : 'none');
            }
        }
    }

    $sku_filter = trim((string) $sku_filter);
    $out = [];
    foreach ($rows as $r) {
        $ar_id = (int) $r['ar_id'];
        $en_id = !empty($r['en_id']) ? (int) $r['en_id'] : 0;
        $sku = $sku_map[$ar_id] ?? ($en_id ? ($sku_map[$en_id] ?? '') : '');

        if ($sku_filter !== '' && stripos((string) $sku, $sku_filter) === false)
            continue;

        $ar_name = (string) $r['ar_name'];
        $en_name = (string) ($r['en_name'] ?? '');
        $ar_desc = trim(wp_strip_all_tags((string) $r['ar_desc']));
        $en_desc = trim(wp_strip_all_tags((string) ($r['en_desc'] ?? '')));
        $en_live = $en_id && !in_array($r['en_status'], ['trash', 'auto-draft'], true);

        $missing_en = !$en_live;
        $name_bad = $en_live && ($en_name === '' || $en_name === $ar_name || erpgulf_gt_tr_has_arabic($en_name));
        $desc_bad = $en_live && $ar_desc !== '' && ($en_desc === '' || erpgulf_gt_tr_has_arabic($en_desc));
        $ar_cn = $compat_n[$ar_id] ?? 0;
        $en_cn = $en_id ? ($compat_n[$en_id] ?? 0) : 0;
        // Compatibility: EN twin is missing rows the AR has (count-based, language-neutral).
        $compat_bad = $en_live && $ar_cn > 0 && $en_cn < $ar_cn;
        // Category: EN twin carries an Arabic-named product_cat (untranslated category).
        $cat_bad = $en_live && !empty($en_cat_bad[$en_id]);

        $issues = [];
        if ($missing_en) {
            $issues[] = 'Missing English';
        } else {
            if ($name_bad)
                $issues[] = 'Name';
            if ($desc_bad)
                $issues[] = 'Description';
            if ($compat_bad)
                $issues[] = 'Compatibility';
            if ($cat_bad)
                $issues[] = 'Category';
        }
        $has_issue = !empty($issues);
        if ($only_issues && !$has_issue)
            continue;

        $out[] = [
            'sku' => (string) $sku,
            'ar_id' => $ar_id,
            'en_id' => $en_id,
            'ar_name' => mb_substr($ar_name, 0, 120),
            'en_name' => mb_substr($en_name, 0, 120),
            'ar_desc' => mb_substr($ar_desc, 0, 80),
            'en_desc' => mb_substr($en_desc, 0, 80),
            'ar_compat' => $ar_cn,
            'en_compat' => $en_cn,
            'promo' => $promo_map[(string) $sku] ?? 'none',
            'issue' => implode(' + ', $issues),
            'has_issue' => $has_issue ? 1 : 0,
        ];
        if ($limit && count($out) >= $limit)
            break;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────
// GET /translation-report
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_tr_report(WP_REST_Request $req)
{
    $rows = erpgulf_gt_tr_build_rows(
        (string) $req->get_param('sku'),
        (bool) $req->get_param('only_issues'),
        (int) $req->get_param('limit')
    );
    return new WP_REST_Response(['rows' => $rows, 'total' => count($rows)], 200);
}

// ─────────────────────────────────────────────────────────────────
// TRANSLATE — progress state
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_tr_state(): array
{
    $s = get_option(ERPGULF_GT_TR_OPTION, []);
    return is_array($s) ? $s : [];
}

function erpgulf_gt_tr_set_progress($phase, $percent, $extra = [])
{
    $s = erpgulf_gt_tr_state();
    $s['phase'] = $phase;
    $s['percent'] = (int) $percent;
    foreach ($extra as $k => $v) {
        $s[$k] = $v;
    }
    update_option(ERPGULF_GT_TR_OPTION, $s, false);
}

// ─────────────────────────────────────────────────────────────────
// TRANSLATE — core (one product), reusing the plugin's own functions
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_tr_translate_post($post_id): array
{
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'product') {
        return ['ok' => false, 'error' => 'not a product'];
    }
    if (!function_exists('erpgulf_gt_ai_providers') || !function_exists('erpgulf_gt_save_to_wpml')) {
        return ['ok' => false, 'error' => 'translator plugin functions not available'];
    }

    $registry = erpgulf_gt_ai_providers();
    $active_key = erpgulf_gt_active_provider();
    if (!isset($registry[$active_key])) {
        return ['ok' => false, 'error' => 'no active provider'];
    }
    $active_info = $registry[$active_key];
    $translate_fn = 'erpgulf_gt_translate_' . $active_key;
    if (!function_exists($translate_fn)) {
        return ['ok' => false, 'error' => "provider fn {$translate_fn} missing"];
    }
    if (empty(get_option($active_info['key_option'], ''))) {
        return ['ok' => false, 'error' => 'API key not set'];
    }

    $settings = [
        'gemini_api_key' => get_option('erpgulf_gt_gemini_api_key', ''),
        'gemini_model' => get_option('erpgulf_gt_gemini_model', 'gemini-2.0-flash'),
        'openai_api_key' => get_option('erpgulf_gt_openai_api_key', ''),
        'openai_model' => get_option('erpgulf_gt_openai_model', 'gpt-4o-mini'),
        'claude_api_key' => get_option('erpgulf_gt_claude_api_key', ''),
        'claude_model' => get_option('erpgulf_gt_claude_model', 'claude-haiku-4-5-20251001'),
    ];
    $source_lang = get_option('erpgulf_gt_source_lang', 'Arabic');
    $target_lang = get_option('erpgulf_gt_target_lang', 'English');
    $fields = (array) apply_filters(
        'erpgulf_gt_fields',
        get_option('erpgulf_gt_fields', ['title', 'content', 'excerpt']),
        $post_id
    );

    $translations = [];
    foreach ($fields as $field) {
        if (strpos($field, 'repeater:') === 0) {
            if (!function_exists('erpgulf_gt_translate_repeater'))
                continue;
            $rk = preg_replace('/^repeater:/', '', $field);
            $res = erpgulf_gt_translate_repeater($post_id, $rk, $translate_fn, $settings, $source_lang, $target_lang);
            if (!is_wp_error($res))
                $translations[$field] = $res;
            continue;
        }
        if ($field === 'title')
            $text = $post->post_title;
        elseif ($field === 'content')
            $text = $post->post_content;
        elseif ($field === 'excerpt')
            $text = $post->post_excerpt;
        else
            $text = (string) get_post_meta($post_id, preg_replace('/^meta:/', '', $field), true);

        if (trim((string) $text) === '')
            continue;

        $prompt = "Translate the following {$source_lang} product text to {$target_lang}. "
            . 'Return only the translated text. No explanation. No quotes. No preamble. '
            . "Preserve any HTML tags exactly as they are.\n\n{$text}";
        $prompt = (string) apply_filters('erpgulf_gt_prompt', $prompt, $field, $text, $post_id);
        $result = $translate_fn($prompt, $settings);
        if (is_wp_error($result))
            continue;
        $translations[$field] = (string) apply_filters('erpgulf_gt_translated_text', $result, $text, $field, $post_id);
    }

    if (empty($translations)) {
        return ['ok' => false, 'error' => 'nothing translated (empty source or all API calls failed)'];
    }

    $save = erpgulf_gt_save_to_wpml($post_id, $translations, $target_lang, $translate_fn, $settings);
    if (is_wp_error($save)) {
        return ['ok' => false, 'error' => $save->get_error_message()];
    }
    $en_id = (int) $save;

    // Mirror compatibility rows AR -> EN so the report's Compatibility flag clears
    // (brand/model are fitment identifiers — copied as-is, not translated).
    $cc = (int) get_post_meta($post_id, 'add_compactable_details', true);
    if ($en_id && $cc > 0) {
        update_post_meta($en_id, 'add_compactable_details', $cc);
        $ref = get_post_meta($post_id, '_add_compactable_details', true);
        if ($ref)
            update_post_meta($en_id, '_add_compactable_details', $ref);
        for ($i = 0; $i < $cc; $i++) {
            foreach (['brand', 'model', 'variant', 'years', 'engine_size'] as $sub) {
                $k = "add_compactable_details_{$i}_{$sub}";
                $v = get_post_meta($post_id, $k, true);
                if ($v !== '')
                    update_post_meta($en_id, $k, $v);
                $sr = get_post_meta($post_id, '_' . $k, true);
                if ($sr)
                    update_post_meta($en_id, '_' . $k, $sr);
            }
        }
        if (function_exists('erpgulf_gt_fitment_reindex_product')) {
            erpgulf_gt_fitment_reindex_product($en_id);
        }
    }
    return ['ok' => true, 'en_id' => $en_id];
}

// ─────────────────────────────────────────────────────────────────
// POST /translate-run  — queue a background batch translate
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_tr_run_start(WP_REST_Request $req)
{
    $state = erpgulf_gt_tr_state();
    if (!empty($state['running'])) {
        $age = time() - (int) ($state['started'] ?? 0);
        if ($age < ERPGULF_GT_TR_MAXAGE) {
            return new WP_REST_Response([
                'queued' => false,
                'running' => true,
                'message' => 'A translate batch is already running.',
            ], 409);
        }
    }

    $sku = (string) $req->get_param('sku');
    $limit = (int) $req->get_param('limit');
    if ($limit <= 0)
        $limit = 50;

    // Collect the flagged (untranslated) products, capped at $limit.
    $rows = erpgulf_gt_tr_build_rows($sku, true, $limit);
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['ar_id'];
    }

    if (empty($ids)) {
        return new WP_REST_Response([
            'queued' => false,
            'running' => false,
            'count' => 0,
            'message' => 'No untranslated products found for this scope.',
        ], 200);
    }

    update_option(ERPGULF_GT_TR_OPTION, [
        'running' => true,
        'started' => time(),
        'finished' => null,
        'result' => null,
        'phase' => 'Queued…',
        'percent' => 0,
        'total' => count($ids),
        'done' => 0,
        'ok' => 0,
        'failed' => 0,
    ], false);

    $args = ['ids' => $ids];
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action(ERPGULF_GT_TR_HOOK, [$args], 'erpgulf-gt');
    } elseif (function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time(), ERPGULF_GT_TR_HOOK, [$args], 'erpgulf-gt');
    } else {
        wp_schedule_single_event(time(), ERPGULF_GT_TR_HOOK, [$args]);
        spawn_cron();
    }

    return new WP_REST_Response([
        'queued' => true,
        'running' => true,
        'count' => count($ids),
        'message' => count($ids) . ' product(s) queued for translation.',
    ], 202);
}

// ─────────────────────────────────────────────────────────────────
// GET /translate-status
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_tr_run_status()
{
    $state = erpgulf_gt_tr_state();
    if (!empty($state['running'])) {
        $age = time() - (int) ($state['started'] ?? 0);
        if ($age > ERPGULF_GT_TR_MAXAGE) {
            $state['running'] = false;
            $state['finished'] = time();
            $state['result'] = ['success' => false, 'message' => 'Timed out / stale — reset by status check.'];
            update_option(ERPGULF_GT_TR_OPTION, $state, false);
        }
    }
    return new WP_REST_Response([
        'running' => (bool) ($state['running'] ?? false),
        'phase' => $state['phase'] ?? null,
        'percent' => isset($state['percent']) ? (int) $state['percent'] : null,
        'total' => $state['total'] ?? null,
        'done' => $state['done'] ?? null,
        'ok' => $state['ok'] ?? null,
        'failed' => $state['failed'] ?? null,
        'finished' => $state['finished'] ?? null,
        'result' => $state['result'] ?? null,
    ], 200);
}

// ─────────────────────────────────────────────────────────────────
// BACKGROUND RUNNER — translate each product, report progress
// ─────────────────────────────────────────────────────────────────

add_action(ERPGULF_GT_TR_HOOK, 'erpgulf_gt_tr_run_worker', 10, 1);

function erpgulf_gt_tr_run_worker($args = [])
{
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $old_er = error_reporting(0);

    $args = is_array($args) ? $args : [];
    $ids = isset($args['ids']) && is_array($args['ids']) ? $args['ids'] : [];
    $total = count($ids);
    $done = 0;
    $ok = 0;
    $failed = 0;

    foreach ($ids as $pid) {
        $r = erpgulf_gt_tr_translate_post((int) $pid);
        if (!empty($r['ok']))
            $ok++;
        else
            $failed++;
        $done++;
        if ($done % 3 === 0 || $done === $total) {
            $pct = $total ? (int) round(100 * $done / $total) : 100;
            erpgulf_gt_tr_set_progress(
                "Translating {$done}/{$total}…",
                min($pct, 99),
                ['done' => $done, 'ok' => $ok, 'failed' => $failed]
            );
        }
    }

    $msg = "Translated {$ok}, failed {$failed}, of {$total}.";
    $state = erpgulf_gt_tr_state();
    $state['running'] = false;
    $state['finished'] = time();
    $state['phase'] = 'Done';
    $state['percent'] = 100;
    $state['done'] = $done;
    $state['ok'] = $ok;
    $state['failed'] = $failed;
    $state['result'] = ['success' => ($failed === 0), 'message' => $msg];
    update_option(ERPGULF_GT_TR_OPTION, $state, false);

    do_action('erpgulf_gt_tr_run_done', $state['result']);
    if (function_exists('error_log')) {
        error_log('[ERPGulf GT translate] ' . $msg);
    }
    error_reporting($old_er);
}
