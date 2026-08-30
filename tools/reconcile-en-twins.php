<?php

/**
 * reconcile-en-twins.php
 *
 * SECOND-PASS cleanup for MRKBATX: removes MISLINKED English twins.
 *
 * After the trid-NULL orphan cleanup, some SKUs still have 3+ products because
 * old code created English twins with a WRONG/standalone trid (not the Arabic
 * original's). Example:
 *   342679 ar  trid 3023033        <- original            KEEP
 *   383323 en  trid 3023033        <- correct twin        KEEP
 *   386455 en  trid 3038788        <- stray/wrong trid    DELETE
 *
 * RULE (per SKU):
 *   - Require exactly ONE Arabic original (language_code='ar'). If 0 or >1,
 *     SKIP the SKU and report it (don't guess on the source of truth).
 *   - Among English products (language_code='en') with that SKU:
 *       * KEEPER = the en whose trid == the ar's trid.
 *       * If none matches, KEEPER = the lowest-ID en, and we RE-LINK it onto the
 *         ar's trid (so the product keeps a translation).
 *       * All other en products are DELETED (unless referenced by an order).
 *
 * NEVER deletes the Arabic original. NEVER deletes a twin that's in an order.
 *
 * USAGE (staging first, DB backup taken):
 *   Dry run (default):
 *     wp eval-file reconcile-en-twins.php --path=/var/www/woocommerce
 *   Real run:
 *     CLEANUP_GO=1 wp eval-file reconcile-en-twins.php --path=/var/www/woocommerce
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: CLEANUP_GO=1 wp eval-file reconcile-en-twins.php --path=/var/www/woocommerce\n");
    exit(1);
}

global $wpdb, $args;
$GO = false;
if (isset($args) && is_array($args) && in_array('go', $args, true))
    $GO = true;
if (!$GO && !empty($GLOBALS['argv']) && is_array($GLOBALS['argv']) && in_array('go', $GLOBALS['argv'], true))
    $GO = true;
if (!$GO && getenv('CLEANUP_GO') === '1')
    $GO = true;

$BATCH = 200;
$icl = $wpdb->prefix . 'icl_translations';

echo "==========================================================\n";
echo ' Reconcile EN twins  —  MODE: ' . ($GO ? 'DELETE (permanent)' : 'DRY RUN (no changes)') . "\n";
echo "==========================================================\n";

/*
 * 1) Every published product with a SKU + its language/trid.
 */
$rows = $wpdb->get_results("
    SELECT p.ID AS id, pm.meta_value AS sku, t.language_code AS lang, t.trid AS trid
    FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value <> ''
    LEFT JOIN {$icl} t ON t.element_id = p.ID AND t.element_type = 'post_product'
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
");
if (!$rows) {
    echo "No products found.\n";
    return;
}

$bySku = [];
foreach ($rows as $r) {
    $bySku[$r->sku][] = $r;
}

$to_delete = [];  // en product ids to remove
$to_relink = [];  // [en_id => ar_trid] keepers that need re-linking
$skip_no_ar = [];  // SKUs with zero ar original
$skip_multi_ar = [];  // SKUs with >1 ar original

foreach ($bySku as $sku => $list) {
    $ar = array_values(array_filter($list, fn($r) => $r->lang === 'ar'));
    $en = array_values(array_filter($list, fn($r) => $r->lang === 'en'));

    if (count($en) <= 1)
        continue;  // 0 or 1 en -> nothing to reconcile
    if (count($ar) === 0) {
        $skip_no_ar[] = $sku;
        continue;
    }
    if (count($ar) > 1) {
        $skip_multi_ar[] = $sku;
        continue;
    }

    $ar_trid = $ar[0]->trid;

    // Keeper: the en on the ar's trid, else the lowest-ID en (to be re-linked).
    $keeper = null;
    foreach ($en as $e) {
        if ($ar_trid !== null && $e->trid == $ar_trid) {
            $keeper = $e;
            break;
        }
    }
    if (!$keeper) {
        usort($en, fn($a, $b) => (int) $a->id - (int) $b->id);
        $keeper = $en[0];
        if ($ar_trid !== null)
            $to_relink[(int) $keeper->id] = (int) $ar_trid;
    }

    foreach ($en as $e) {
        if ((int) $e->id === (int) $keeper->id)
            continue;
        $to_delete[] = (int) $e->id;
    }
}
$to_delete = array_values(array_unique($to_delete));

echo 'Distinct SKUs:                         ' . count($bySku) . "\n";
echo 'EN twins to remove (mislinked):        ' . count($to_delete) . "\n";
echo 'Keepers needing re-link to AR trid:    ' . count($to_relink) . "\n";
echo 'SKUs skipped — no AR original:         ' . count($skip_no_ar) . "\n";
echo 'SKUs skipped — multiple AR originals:  ' . count($skip_multi_ar) . "\n";

/*
 * 2) Order safety.
 */
$in_orders = [];
foreach (array_chunk($to_delete, 500) as $chunk) {
    if (!$chunk)
        continue;
    $in = implode(',', array_map('intval', $chunk));
    foreach ($wpdb->get_col("
        SELECT DISTINCT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
        WHERE meta_key='_product_id' AND meta_value IN ($in)
    ") as $pid) {
        $in_orders[(int) $pid] = true;
    }
}
$skipped_orders = array_values(array_filter($to_delete, fn($id) => isset($in_orders[$id])));
$to_delete = array_values(array_filter($to_delete, fn($id) => !isset($in_orders[$id])));

echo 'EN twins skipped (in orders, kept):    ' . count($skipped_orders) . "\n";
echo 'EN twins to DELETE:                    ' . count($to_delete) . "\n";
echo "----------------------------------------------------------\n";

$log = __DIR__ . '/reconcile-en-ids.txt';
@file_put_contents($log,
    'DELETE (' . count($to_delete) . "):\n" . implode("\n", $to_delete) . "\n\n"
        . 'RELINK_KEEPERS (' . count($to_relink) . "):\n"
        . implode("\n", array_map(fn($k) => $k . ' -> trid ' . $to_relink[$k], array_keys($to_relink))) . "\n\n"
        . 'SKIPPED_IN_ORDERS (' . count($skipped_orders) . "):\n" . implode("\n", $skipped_orders) . "\n\n"
        . "SKIP_NO_AR:\n" . implode("\n", $skip_no_ar) . "\n\n"
        . "SKIP_MULTI_AR:\n" . implode("\n", $skip_multi_ar) . "\n");
echo "Full lists written to: {$log}\n";

if (!$GO) {
    echo "----------------------------------------------------------\n";
    echo "DRY RUN complete. Nothing changed.\n";
    echo 'Sample to delete: ' . implode(', ', array_slice($to_delete, 0, 30)) . "\n";
    echo "Set CLEANUP_GO=1 to apply.\n";
    return;
}

/*
 * 3) Re-link keepers onto the AR trid (so no product loses its translation).
 */
$relinked = 0;
foreach ($to_relink as $en_id => $ar_trid) {
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT translation_id FROM {$icl} WHERE element_id=%d AND element_type='post_product'", $en_id
    ));
    if ($existing) {
        $wpdb->update($icl,
            ['trid' => $ar_trid, 'language_code' => 'en', 'source_language_code' => 'ar'],
            ['translation_id' => $existing]);
    } else {
        $wpdb->insert($icl, [
            'element_type' => 'post_product',
            'element_id' => $en_id,
            'trid' => $ar_trid,
            'language_code' => 'en',
            'source_language_code' => 'ar',
        ]);
    }
    $relinked++;
}
echo "Re-linked keepers: {$relinked}\n";

/*
 * 4) Delete the mislinked EN twins (permanent), batched.
 */
$done = 0;
foreach (array_chunk($to_delete, $BATCH) as $chunk) {
    foreach ($chunk as $id) {
        wp_delete_post($id, true);
        $done++;
    }
    if (function_exists('wp_cache_flush'))
        wp_cache_flush();
    echo "  ...deleted {$done}/" . count($to_delete) . "\n";
}
echo "----------------------------------------------------------\n";
echo "DONE. Deleted {$done} mislinked EN twins, re-linked {$relinked} keepers.\n";
echo "Next: rebuild the Ajax Search index.\n";
