<?php

/**
 * cleanup-stray-trid.php
 *
 * THIRD / FINAL pass for MRKBATX duplicate SKUs.
 *
 * After orphan (trid-NULL) and mislinked-EN cleanups, a few SKUs still have 3+
 * products because of a "stray-trid" product: English content mis-registered as
 * language 'ar' on its OWN trid, separate from the genuine Arabic original.
 * Example (one SKU):
 *   343370  ar  trid 3023724  Arabic title   <- genuine original   KEEP
 *   382667  en  trid 3023724  English title  <- correct twin       KEEP
 *   376176  ar  trid 3038135  English title  <- stray              DELETE
 *
 * RULE (per SKU with >2 published products):
 *   - CANONICAL original = the language='ar' product whose TITLE contains Arabic
 *     script. There must be exactly ONE; otherwise SKIP + report (no guessing).
 *   - KEEP every product on the canonical original's trid (the ar + its twin).
 *   - DELETE every other product for that SKU (different trid), unless it is
 *     referenced by an order.
 *
 * Never deletes a product on the canonical trid. Never deletes an ordered product.
 *
 * USAGE (staging first; DB backup taken):
 *   Dry run:   wp eval-file cleanup-stray-trid.php --path=/var/www/woocommerce
 *   Real run:  CLEANUP_GO=1 wp eval-file cleanup-stray-trid.php --path=/var/www/woocommerce
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: CLEANUP_GO=1 wp eval-file cleanup-stray-trid.php --path=/var/www/woocommerce\n");
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

echo "==========================================================\n";
echo ' Stray-trid cleanup  —  MODE: ' . ($GO ? 'DELETE (permanent)' : 'DRY RUN (no changes)') . "\n";
echo "==========================================================\n";

$rows = $wpdb->get_results("
    SELECT p.ID AS id, p.post_title AS title, pm.meta_value AS sku,
           t.language_code AS lang, t.trid AS trid
    FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value <> ''
    LEFT JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = 'post_product'
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

$has_arabic = fn($s) => (bool) preg_match('/[\x{0600}-\x{06FF}]/u', (string) $s);

$to_delete = [];
$skip_ambig = [];  // SKUs where we couldn't pick a single canonical original

foreach ($bySku as $sku => $list) {
    if (count($list) <= 2)
        continue;  // ar + en is fine

    // Canonical = the ar-language product with an Arabic-script title.
    $canon = array_values(array_filter($list, fn($r) => $r->lang === 'ar' && $has_arabic($r->title)));
    if (count($canon) !== 1 || $canon[0]->trid === null) {
        $skip_ambig[] = $sku . ' (' . count($list) . ' products)';
        continue;
    }
    $canon_trid = (int) $canon[0]->trid;

    foreach ($list as $r) {
        if ((int) $r->trid === $canon_trid)
            continue;  // keep ar + its twin
        $to_delete[] = (int) $r->id;  // stray -> delete
    }
}
$to_delete = array_values(array_unique($to_delete));

echo 'Distinct SKUs:                         ' . count($bySku) . "\n";
echo 'Stray products to remove:              ' . count($to_delete) . "\n";
echo 'SKUs skipped — ambiguous (review):     ' . count($skip_ambig) . "\n";

// Order safety.
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

echo 'Stray skipped (in orders, kept):       ' . count($skipped_orders) . "\n";
echo 'Stray to DELETE:                       ' . count($to_delete) . "\n";
echo "----------------------------------------------------------\n";

$log = __DIR__ . '/stray-trid-ids.txt';
@file_put_contents($log,
    'DELETE (' . count($to_delete) . "):\n" . implode("\n", $to_delete) . "\n\n"
        . 'SKIPPED_IN_ORDERS (' . count($skipped_orders) . "):\n" . implode("\n", $skipped_orders) . "\n\n"
        . "SKIP_AMBIGUOUS:\n" . implode("\n", $skip_ambig) . "\n");
echo "Full lists written to: {$log}\n";
if ($skip_ambig) {
    echo 'Ambiguous SKUs (handle manually): ' . implode(' | ', array_slice($skip_ambig, 0, 20)) . "\n";
}

if (!$GO) {
    echo "----------------------------------------------------------\n";
    echo "DRY RUN complete. Nothing changed.\n";
    echo 'Sample to delete: ' . implode(', ', array_slice($to_delete, 0, 30)) . "\n";
    echo "Set CLEANUP_GO=1 to apply.\n";
    return;
}

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
echo "DONE. Deleted {$done} stray products.\n";
echo "Next: re-check skus_still_duplicated, then rebuild the Ajax Search index.\n";
