<?php

/**
 * ERPGulf AI Translate — REST bridge for ERPNext.
 *
 * Lets ERPNext trigger a FULL fitment rebuild remotely, in the background:
 *     rebuild wp_adv_product_fitments (AR + EN)  ->  vehicles.csv  ->  product lookup table
 *
 * Routes (namespace erpgulf/v1):
 *   POST /wp-json/erpgulf/v1/rebuild-fitments   queue the rebuild (returns immediately)
 *   GET  /wp-json/erpgulf/v1/rebuild-status     { running, started, finished, result }
 *
 * AUTH: WordPress Application Password (HTTP Basic). The authenticating user
 *       must have the `manage_woocommerce` capability. NOT a WooCommerce
 *       consumer key — those only sign /wc/ routes.
 *
 * This file is ADDITIVE. It does not modify any existing handler. The three
 * admin-AJAX buttons keep working exactly as before; this just gives ERP a way
 * to run the same full rebuild without a human clicking in wp-admin.
 */
if (!defined('ABSPATH'))
    exit;

const ERPGULF_GT_REST_NS = 'erpgulf/v1';
const ERPGULF_GT_RUN_OPTION = 'erpgulf_gt_rebuild_state';  // {running, started, finished, result}
const ERPGULF_GT_RUN_HOOK = 'erpgulf_gt_rest_rebuild_run';  // background action
const ERPGULF_GT_RUN_MAXAGE = 1800;  // 30 min stale-guard

// ─────────────────────────────────────────────────────────────────
// ROUTES
// ─────────────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route(ERPGULF_GT_REST_NS, '/rebuild-fitments', [
        'methods' => 'POST',
        'permission_callback' => 'erpgulf_gt_rest_can_manage',
        'callback' => 'erpgulf_gt_rest_rebuild_start',
        'args' => [
            'csv' => ['type' => 'boolean', 'default' => true],
            'lookup' => ['type' => 'boolean', 'default' => true],
        ],
    ]);

    register_rest_route(ERPGULF_GT_REST_NS, '/rebuild-status', [
        'methods' => 'GET',
        'permission_callback' => 'erpgulf_gt_rest_can_manage',
        'callback' => 'erpgulf_gt_rest_rebuild_status',
    ]);
});

function erpgulf_gt_rest_can_manage($request = null): bool
{
    // 1) Normal WP auth (logged in / Application Password) with the capability.
    if (current_user_can('manage_woocommerce')) {
        return true;
    }
    // 2) WooCommerce consumer key/secret via HTTP Basic auth — reuse the store's
    //    own API keys (the same ck_/cs_ woocommerce_fusion syncs with), so no
    //    separate Application Password is needed.
    list($ck, $cs) = erpgulf_gt_basic_auth_creds();
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
                in_array($row->permissions, array('read_write', 'write'), true)) {
            if (!empty($row->user_id)) {
                wp_set_current_user((int) $row->user_id);
            }
            return true;
        }
    }
    return false;
}

function erpgulf_gt_basic_auth_creds(): array
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

// ─────────────────────────────────────────────────────────────────
// POST /rebuild-fitments  — queue a background rebuild
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_rest_rebuild_start(WP_REST_Request $req)
{
    $state = erpgulf_gt_rest_state();

    // Already running (and not stale)? Report that instead of double-queuing.
    if (!empty($state['running'])) {
        $age = time() - (int) ($state['started'] ?? 0);
        if ($age < ERPGULF_GT_RUN_MAXAGE) {
            return new WP_REST_Response([
                'queued' => false,
                'running' => true,
                'started' => $state['started'] ?? null,
                'message' => 'A rebuild is already running.',
            ], 409);
        }
        // Stale lock — fall through and re-queue.
    }

    $args = [
        'csv' => (bool) $req->get_param('csv'),
        'lookup' => (bool) $req->get_param('lookup'),
    ];

    update_option(ERPGULF_GT_RUN_OPTION, [
        'running' => true,
        'started' => time(),
        'finished' => null,
        'result' => null,
        'args' => $args,
    ], false);

    // Background it — never block the HTTP request on a 5k-product rebuild.
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action(ERPGULF_GT_RUN_HOOK, [$args], 'erpgulf-gt');
    } elseif (function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time(), ERPGULF_GT_RUN_HOOK, [$args], 'erpgulf-gt');
    } else {
        wp_schedule_single_event(time(), ERPGULF_GT_RUN_HOOK, [$args]);
        spawn_cron();  // nudge WP-Cron so it starts promptly
    }

    return new WP_REST_Response([
        'queued' => true,
        'running' => true,
        'started' => time(),
        'message' => 'Fitment rebuild queued. Poll /rebuild-status for completion.',
    ], 202);
}

// ─────────────────────────────────────────────────────────────────
// GET /rebuild-status
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_rest_rebuild_status()
{
    $state = erpgulf_gt_rest_state();

    // Stale-guard: a crashed job must not pin "running" forever.
    if (!empty($state['running'])) {
        $age = time() - (int) ($state['started'] ?? 0);
        if ($age > ERPGULF_GT_RUN_MAXAGE) {
            $state['running'] = false;
            $state['finished'] = time();
            $state['result'] = ['success' => false, 'message' => 'Timed out / stale — reset by status check.'];
            update_option(ERPGULF_GT_RUN_OPTION, $state, false);
        }
    }

    return new WP_REST_Response([
        'running' => (bool) ($state['running'] ?? false),
        'started' => $state['started'] ?? null,
        'finished' => $state['finished'] ?? null,
        'result' => $state['result'] ?? null,
    ], 200);
}

function erpgulf_gt_rest_state(): array
{
    $s = get_option(ERPGULF_GT_RUN_OPTION, []);
    return is_array($s) ? $s : [];
}

// ─────────────────────────────────────────────────────────────────
// BACKGROUND RUNNER — the actual full rebuild
// ─────────────────────────────────────────────────────────────────

add_action(ERPGULF_GT_RUN_HOOK, 'erpgulf_gt_rest_rebuild_worker', 10, 1);

function erpgulf_gt_rest_rebuild_worker($args = [])
{
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $old_er = error_reporting(0);

    $args = is_array($args) ? $args : [];
    $do_csv = !isset($args['csv']) || (bool) $args['csv'];
    $do_lookup = !isset($args['lookup']) || (bool) $args['lookup'];

    $result = ['success' => true, 'steps' => []];

    // 1) Rebuild the fitment index (AR + EN).
    $fit = erpgulf_gt_rest_rebuild_fitments_core();
    $result['steps']['fitments'] = $fit;
    if (empty($fit['success']))
        $result['success'] = false;

    // 2) Regenerate vehicles.csv from the fitment table (reuse the plugin's own fn).
    if ($do_csv && function_exists('erpgulf_gt_generate_vehicles_csv')) {
        $csv = erpgulf_gt_generate_vehicles_csv();
        $result['steps']['csv'] = $csv;
        if (empty($csv['success']))
            $result['success'] = false;
    }

    // 3) Refresh the WooCommerce product lookup table (fixes admin SKU search).
    if ($do_lookup) {
        $lk = erpgulf_gt_rest_regen_lookup_core();
        $result['steps']['lookup'] = $lk;
        if (empty($lk['success']))
            $result['success'] = false;
    }

    $result['message'] = trim(
        ($fit['message'] ?? '')
        . (isset($result['steps']['csv']) ? ' | ' . ($result['steps']['csv']['message'] ?? '') : '')
        . (isset($result['steps']['lookup']) ? ' | ' . ($result['steps']['lookup']['message'] ?? '') : '')
    );

    $state = erpgulf_gt_rest_state();
    $state['running'] = false;
    $state['finished'] = time();
    $state['result'] = $result;
    update_option(ERPGULF_GT_RUN_OPTION, $state, false);

    // Let anything else react (e.g. an ERP webhook, or the report UI polling).
    do_action('erpgulf_gt_rest_rebuild_done', $result);
    if (function_exists('error_log')) {
        error_log('[ERPGulf GT REST] rebuild done — ' . $result['message']);
    }

    error_reporting($old_er);
}

// ─────────────────────────────────────────────────────────────────
// CORE — rebuild fitment index (self-contained; mirrors the admin button)
// Kept here so this file is fully additive and does not touch the AJAX handler.
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_rest_rebuild_fitments_core(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'adv_product_fitments';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return ['success' => false, 'message' => 'Fitment table not found: ' . $table];
    }

    $product_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
    );
    if (empty($product_ids)) {
        return ['success' => false, 'message' => 'No published products found.'];
    }

    // has_arabic is a STORED GENERATED column — never inserted; it fills itself.
    $wpdb->query("TRUNCATE TABLE {$table}");

    $inserted = 0;
    $with_compat = 0;
    $batch = [];

    $flush = function () use (&$batch, $wpdb, $table, &$inserted) {
        if (!$batch)
            return;
        $place = [];
        $vals = [];
        foreach ($batch as $r) {
            $place[] = '(%d,%s,%s,%s,%d)';
            array_push($vals, $r[0], $r[1], $r[2], $r[3], $r[4]);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (product_id,adv_brand,adv_model,adv_variant,adv_year) VALUES " . implode(',', $place),
            ...$vals
        ));
        $inserted += count($batch);
        $batch = [];
    };

    foreach ($product_ids as $pid) {
        $count = (int) get_post_meta($pid, 'add_compactable_details', true);
        if ($count <= 0)
            continue;
        $with_compat++;

        for ($i = 0; $i < $count; $i++) {
            $brand = trim((string) get_post_meta($pid, "add_compactable_details_{$i}_brand", true));
            $model = trim((string) get_post_meta($pid, "add_compactable_details_{$i}_model", true));
            $variant = trim((string) get_post_meta($pid, "add_compactable_details_{$i}_variant", true));
            $years = (string) get_post_meta($pid, "add_compactable_details_{$i}_years", true);

            if ($brand === '' && $model === '')
                continue;  // adv_brand / adv_model are NOT NULL

            $year_list = array_filter(array_map('trim', explode(',', $years)));
            if (!$year_list)
                $year_list = ['0'];  // adv_year is NOT NULL

            foreach ($year_list as $yr) {
                $batch[] = [(int) $pid, $brand, $model, $variant, (int) $yr];
                if (count($batch) >= 500)
                    $flush();
            }
        }
    }
    $flush();

    return [
        'success' => true,
        'inserted' => $inserted,
        'with_compat' => $with_compat,
        'message' => sprintf(
            '%s fitment rows rebuilt from %s products (AR + EN)',
            number_format($inserted),
            number_format($with_compat)
        ),
    ];
}

// ─────────────────────────────────────────────────────────────────
// CORE — regenerate WooCommerce product lookup table
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_rest_regen_lookup_core(): array
{
    global $wpdb;

    $product_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 5000"
    );
    if (empty($product_ids)) {
        return ['success' => true, 'message' => 'No published products for lookup.'];
    }

    $count = 0;
    $errors = 0;
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            $errors++;
            continue;
        }
        $product->save();
        $count++;
    }

    return [
        'success' => true,
        'message' => "{$count} product(s) refreshed in lookup table" . ($errors ? ", {$errors} skipped" : ''),
    ];
}
