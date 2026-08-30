<?php

/**
 * cleanup-orphan-products.php
 *
 * One-time cleanup for the SKU-duplicate / unlinked-twin mess on MRKBATX.
 *
 * WHAT IT CONSIDERS AN ORPHAN (safe to delete):
 *   A PUBLISHED product that
 *     (a) has a non-empty SKU,
 *     (b) has NO WPML link (no row in wp_icl_translations => trid IS NULL), AND
 *     (c) shares that SKU with at least one WPML-LINKED published product
 *         (i.e. a real ar original and/or its en twin still exist to keep).
 *
 * It NEVER touches:
 *   - products that ARE WPML-linked (the real ar original + its en twin),
 *   - trid-NULL products whose SKU has NO linked sibling (reported, not deleted),
 *   - any orphan that appears in an order (reported as "skipped: in orders").
 *
 * USAGE (run on STAGING first, with a fresh DB backup):
 *   Dry run (default, deletes nothing):
 *     wp eval-file cleanup-orphan-products.php --path=/var/www/woocommerce
 *   Real run (permanent delete):
 *     wp eval-file cleanup-orphan-products.php go --path=/var/www/woocommerce
 *
 * Notes:
 *   - Deletes via wp_delete_post($id, true) so Woo/WPML housekeeping + search
 *     index hooks fire (no raw SQL DELETE).
 *   - Batched with progress output; safe to re-run (idempotent).
 *   - Writes the full candidate ID list to orphan-ids.txt next to this script.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: wp eval-file cleanup-orphan-products.php [go] --path=/var/www/woocommerce\n");
    exit(1);
}

global $wpdb, $args;
$GO = (getenv('CLEANUP_GO') === '1');
$BATCH = 200;

echo "==========================================================\n";
echo ' Orphan product cleanup  —  MODE: ' . ($GO ? 'DELETE (permanent)' : 'DRY RUN (no changes)') . "\n";
echo "==========================================================\n";

/*
 * 1) Pull every published product's id + sku + wpml trid in one pass.
 */
$rows = $wpdb->get_results("
    SELECT p.ID AS id,
           pm.meta_value AS sku,
           t.trid AS trid
    FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} pm
      ON pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value <> ''
    LEFT JOIN {$wpdb->prefix}icl_translations t
      ON t.element_id = p.ID AND t.element_type = 'post_product'
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
");

if (!$rows) {
    echo "No published products with SKUs found. Nothing to do.\n";
    return;
}

/*
 * 2) Group by SKU; a SKU is 'anchored' if it has >=1 WPML-linked product.
 */
$bySku = [];
foreach ($rows as $r) {
    $bySku[$r->sku][] = $r;
}

$orphans = [];  // safe to delete (trid NULL + anchored sku)
$unanchored = [];  // trid NULL but no linked sibling -> DO NOT delete, report only
foreach ($bySku as $sku => $list) {
    $hasLinked = false;
    foreach ($list as $r) {
        if ($r->trid !== null) {
            $hasLinked = true;
            break;
        }
    }
    foreach ($list as $r) {
        if ($r->trid !== null) {
            continue;  // keep all WPML-linked (real ar + en twin)
        }
        if ($hasLinked) {
            $orphans[] = (int) $r->id;  // trid NULL + a linked sibling exists -> orphan
        } else {
            $unanchored[(string) $sku][] = (int) $r->id;
        }
    }
}

$orphans = array_values(array_unique($orphans));

echo 'Published products w/ SKU:        ' . count($rows) . "\n";
echo 'Distinct SKUs:                    ' . count($bySku) . "\n";
echo "Orphan candidates (trid NULL,\n";
echo '  SKU has a linked sibling):      ' . count($orphans) . "\n";
echo "Unanchored trid-NULL SKUs\n";
echo '  (NOT deleted, review):          ' . count($unanchored) . "\n";

/*
 * 3) Order-safety: never delete a product that appears in any order line item.
 */
$in_orders = [];
if ($orphans) {
    foreach (array_chunk($orphans, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $found = $wpdb->get_col("
            SELECT DISTINCT meta_value
            FROM {$wpdb->prefix}woocommerce_order_itemmeta
            WHERE meta_key = '_product_id' AND meta_value IN ($in)
        ");
        foreach ($found as $pid) {
            $in_orders[(int) $pid] = true;
        }
    }
}
$skipped_orders = array_values(array_filter($orphans, function ($id) use ($in_orders) {
    return isset($in_orders[$id]);
}));
$to_delete = array_values(array_filter($orphans, function ($id) use ($in_orders) {
    return !isset($in_orders[$id]);
}));

echo 'Orphans skipped (in orders):      ' . count($skipped_orders) . "\n";
echo 'Orphans to DELETE:                ' . count($to_delete) . "\n";
echo "----------------------------------------------------------\n";

/*
 * 4) Write the candidate list to a file for the record.
 */
$logfile = __DIR__ . '/orphan-ids.txt';
@file_put_contents($logfile,
    'TO_DELETE (' . count($to_delete) . "):\n" . implode("\n", $to_delete) . "\n\n"
        . 'SKIPPED_IN_ORDERS (' . count($skipped_orders) . "):\n" . implode("\n", $skipped_orders) . "\n\n"
        . "UNANCHORED_SKUS (review, not deleted):\n"
        . implode("\n", array_map(function ($sku) use ($unanchored) {
            return $sku . ' => ' . implode(',', $unanchored[$sku]);
        }, array_keys($unanchored))) . "\n");
echo "Full lists written to: {$logfile}\n";

if (!empty($skipped_orders)) {
    echo 'NOTE: ' . count($skipped_orders) . " orphan(s) are referenced by orders and were kept.\n";
    echo '      Sample: ' . implode(', ', array_slice($skipped_orders, 0, 20)) . "\n";
}

if (!$GO) {
    echo "----------------------------------------------------------\n";
    echo "DRY RUN complete. Nothing was deleted.\n";
    echo 'Sample of IDs that WOULD be deleted: ' . implode(', ', array_slice($to_delete, 0, 30)) . "\n";
    echo "Re-run with the 'go' argument to delete permanently.\n";
    return;
}

/*
 * 5) DELETE (permanent), batched, with progress.
 */
echo 'Deleting ' . count($to_delete) . " orphan products...\n";
$done = 0;
foreach (array_chunk($to_delete, $BATCH) as $i => $chunk) {
    foreach ($chunk as $id) {
        wp_delete_post($id, true);  // true = force (bypass trash), fires hooks
        $done++;
    }
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    echo "  ...deleted {$done}/" . count($to_delete) . "\n";
}
echo "----------------------------------------------------------\n";
echo "DONE. Deleted {$done} orphan products.\n";
echo "Next: rebuild the Ajax Search index (Ajax Search for WooCommerce -> Indexer -> Build index).\n";
