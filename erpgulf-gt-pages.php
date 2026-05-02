<?php
/**
 * ERPGulf AI Translate — Pages & Posts
 * Include this file from the main plugin via require_once
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────
// REGISTER SUBMENU
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_submenu_page(
        'erpgulf-woo-ai-translator',
        'Translate Pages & Posts',
        'Pages & Posts',
        'manage_options',
        'erpgulf-gt-pages',
        'erpgulf_gt_pages_render'
    );
}, 20 );

// ─────────────────────────────────────────────────────────────────
// PAGE RENDER
// ─────────────────────────────────────────────────────────────────

function erpgulf_gt_pages_render() {

    $registry    = erpgulf_gt_ai_providers();
    $active_key  = erpgulf_gt_active_provider();
    $active_info = $registry[ $active_key ];
    $source_lang = get_option( 'erpgulf_gt_source_lang', 'Arabic' );
    $target_lang = get_option( 'erpgulf_gt_target_lang', 'English' );
    $lang_code   = erpgulf_gt_lang_name_to_code( $source_lang );
    $nonce       = wp_create_nonce( 'erpgulf_gt_translate_page' );
    $api_key_ok  = ! empty( get_option( $active_info['key_option'], '' ) );

    $post_type = sanitize_key( $_GET['ptype'] ?? 'page' );
    if ( ! in_array( $post_type, [ 'page', 'post' ], true ) ) $post_type = 'page';

    global $wpdb;

    $untranslated = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_type, p.post_date,
                en.element_id as en_id,
                enp.post_status as en_status,
                enp.post_title as en_title
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->prefix}icl_translations icl
                ON icl.element_id = p.ID
               AND icl.element_type = %s
         LEFT JOIN {$wpdb->prefix}icl_translations en
                ON en.trid = icl.trid
               AND en.language_code = %s
         LEFT JOIN {$wpdb->posts} enp ON enp.ID = en.element_id
         WHERE p.post_type = %s
           AND p.post_status = 'publish'
         ORDER BY p.post_title ASC
         LIMIT 500",
        'post_' . $post_type,
        erpgulf_gt_lang_name_to_code( $target_lang ),
        $post_type
    ) );

    $total = count( $untranslated );
    ?>
    <div class="wrap" style="max-width:1000px;">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
            <div>
                <h1 style="margin:0;">📄 Translate Pages &amp; Posts</h1>
                <p style="color:#666;margin:4px 0 0;font-size:13px;">
                    Untranslated <?php echo esc_html( $source_lang ); ?> content
                    → <?php echo esc_html( $target_lang ); ?>
                    via <?php echo esc_html( $active_info['label'] ); ?>
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

        <div style="display:flex;gap:8px;margin-bottom:20px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=erpgulf-gt-pages&ptype=page' ) ); ?>"
               class="button <?php echo $post_type === 'page' ? 'button-primary' : ''; ?>">
                📄 Pages
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=erpgulf-gt-pages&ptype=post' ) ); ?>"
               class="button <?php echo $post_type === 'post' ? 'button-primary' : ''; ?>">
                📝 Posts
            </a>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
            <?php
            $count_translated   = 0;
            $count_untranslated = 0;
            foreach ( $untranslated as $p ) {
                if ( $p->en_id && $p->en_status && $p->en_status !== 'trash' ) $count_translated++;
                else $count_untranslated++;
            }
            ?>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#007cba;"><?php echo $total; ?></div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Total</div>
            </div>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#276749;" id="pg-count-done"><?php echo $count_translated; ?></div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Translated ✅</div>
            </div>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#f08c00;" id="pg-count-failed"><?php echo $count_untranslated; ?></div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Not Translated ⚠️</div>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <span style="font-size:13px;font-weight:600;" id="pg-progress-label">Ready to start</span>
                <span style="font-size:13px;color:#888;" id="pg-progress-count">0 / <?php echo $total; ?></span>
            </div>
            <div style="background:#eee;border-radius:20px;height:12px;overflow:hidden;margin-bottom:16px;">
                <div id="pg-progress-bar"
                     style="height:100%;width:0;border-radius:20px;background:linear-gradient(90deg,#007cba,#00a0d2);transition:width 0.4s ease;">
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button id="pg-btn-start" class="button button-primary" style="min-width:120px;"
                        <?php echo ! $api_key_ok ? 'disabled' : ''; ?>>▶ Start All</button>
                <button id="pg-btn-pause" class="button" style="min-width:120px;display:none;">⏸ Pause</button>
                <button id="pg-btn-resume" class="button button-primary" style="min-width:120px;display:none;">▶ Resume</button>
                <button id="pg-btn-stop" class="button" style="min-width:120px;color:#cc1818;border-color:#cc1818;display:none;">⏹ Stop</button>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <h3 style="margin:0;font-size:14px;">
                        📋 <?php echo ucfirst( $post_type ); ?>s (<?php echo $total; ?>)
                    </h3>
                </div>
                <div style="max-height:520px;overflow-y:auto;font-size:12px;" id="pg-item-list">
                    <?php foreach ( $untranslated as $p ):
                        $is_translated = $p->en_id && $p->en_status && $p->en_status !== 'trash';
                        $en_title      = $p->en_title ?: '';
                        $row_bg        = $is_translated ? '#f9fff9' : '#fff';
                    ?>
                        <div class="pg-item-row"
                             data-id="<?php echo esc_attr( $p->ID ); ?>"
                             data-type="<?php echo esc_attr( $p->post_type ); ?>"
                             data-title="<?php echo esc_attr( $p->post_title ); ?>"
                             style="display:flex;align-items:center;gap:8px;padding:8px 4px;border-bottom:1px solid #f0f0f0;background:<?php echo $row_bg; ?>">
                            <span class="pg-row-icon" style="width:18px;flex-shrink:0;font-size:14px;">
                                <?php echo $is_translated ? '✅' : '⚠️'; ?>
                            </span>
                            <div style="flex:1;min-width:0;">
                                <div style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-weight:500;"
                                     title="<?php echo esc_attr( $p->post_title ); ?>">
                                    <?php echo esc_html( $p->post_title ?: '(no title)' ); ?>
                                </div>
                                <div style="color:#888;font-size:11px;margin-top:1px;">
                                    ID: <?php echo esc_html( $p->ID ); ?>
                                    &nbsp;·&nbsp;
                                    <?php echo esc_html( date( 'Y-m-d', strtotime( $p->post_date ) ) ); ?>
                                    <?php if ( $is_translated && $en_title ): ?>
                                        &nbsp;·&nbsp;
                                        <span style="color:#276749;">EN: <?php echo esc_html( mb_substr( $en_title, 0, 35 ) ); ?></span>
                                    <?php endif; ?>
                                    &nbsp;·&nbsp;
                                    <a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" target="_blank">Edit</a>
                                    <?php if ( $is_translated && $p->en_id ): ?>
                                        &nbsp;·&nbsp;
                                        <a href="<?php echo esc_url( get_edit_post_link( $p->en_id ) ); ?>" target="_blank">Edit EN</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button class="pg-translate-one button button-small"
                                    data-id="<?php echo esc_attr( $p->ID ); ?>"
                                    style="flex-shrink:0;font-size:10px;<?php echo $is_translated ? 'color:#888;' : ''; ?>">
                                <?php echo $is_translated ? '↺ Retranslate' : 'Translate'; ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <?php if ( empty( $untranslated ) ): ?>
                        <p style="color:#888;text-align:center;padding:20px 0;">
                            No <?php echo $post_type; ?>s found.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;">
                <h3 style="margin:0 0 12px;font-size:14px;">📡 Live Log</h3>
                <div id="pg-log"
                     style="max-height:420px;overflow-y:auto;font-size:12px;font-family:monospace;line-height:1.8;color:#333;">
                    <span style="color:#888;">Waiting to start...</span>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {

        var nonce      = '<?php echo esc_js( $nonce ); ?>';
        var targetLang = '<?php echo esc_js( $target_lang ); ?>';
        var queue      = [];
        var done = 0, failed = 0;
        var paused = false, stopped = false, processing = false;

        $('#pg-select-all').on('change', function() {
            $('.pg-item-cb').prop('checked', this.checked);
        });

        function log(icon, msg, color) {
            var $log = $('#pg-log');
            $log.append('<div style="color:' + color + ';padding:2px 0;">' + icon + ' ' + msg + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }

        function updateProgress() {
            var total_sel = done + failed + queue.length;
            var pct = total_sel > 0 ? Math.round((done + failed) / total_sel * 100) : 0;
            $('#pg-progress-bar').css('width', pct + '%');
            $('#pg-progress-count').text((done + failed) + ' / ' + total_sel);
            $('#pg-count-done').text(done);
            $('#pg-count-failed').text(failed);
        }

        function setRowIcon(postId, icon) {
            $('.pg-item-row[data-id="' + postId + '"] .pg-row-icon').text(icon);
        }

        function translateOne(postId, onDone) {
            var $row  = $('.pg-item-row[data-id="' + postId + '"]');
            var title = $row.data('title') || '#' + postId;
            var type  = $row.data('type') || 'page';

            setRowIcon(postId, '🔄');
            $('#pg-progress-label').text('Translating: ' + title.substring(0,50) + '...');
            log('🔄', '[ID:' + postId + '] ' + title.substring(0,50) + '...', '#007cba');

            $.post(ajaxurl, {
                action:    'erpgulf_gt_translate_page',
                post_id:   postId,
                post_type: type,
                nonce:     nonce
            }, function(res) {
                if (res.success) {
                    done++;
                    setRowIcon(postId, '✅');
                    $row.find('.pg-item-cb').prop('checked', false);
                    $row.find('.pg-translate-one').hide();
                    var enTitle = res.data.en_title || title;
                    log('✅', '[ID:' + postId + '] ' + enTitle.substring(0,55), '#276749');
                } else {
                    failed++;
                    setRowIcon(postId, '❌');
                    log('❌', '[ID:' + postId + '] ' + title.substring(0,40) + ' — ' + (res.data || 'Failed'), '#c53030');
                }
                updateProgress();
                onDone && onDone();
            }).fail(function() {
                failed++;
                setRowIcon(postId, '❌');
                log('❌', '[ID:' + postId + '] Network error', '#c53030');
                updateProgress();
                onDone && onDone();
            });
        }

        function processNext() {
            if (stopped || paused || queue.length === 0) {
                processing = false;
                if (stopped || queue.length === 0) onFinished();
                return;
            }
            processing = true;
            translateOne(queue.shift(), function() {
                setTimeout(processNext, 600);
            });
        }

        function onFinished() {
            var msg = stopped ? '⏹ Stopped.' : '✅ All done!';
            $('#pg-progress-label').text(msg + '  ' + done + ' translated, ' + failed + ' failed.');
            log('──', msg + ' ' + done + ' translated · ' + failed + ' failed', '#888');
            $('#pg-btn-pause, #pg-btn-resume, #pg-btn-stop').hide();
            $('#pg-btn-start').show().text('▶ Start Again').prop('disabled', false);
            processing = false;
        }

        $('#pg-btn-start').on('click', function() {
            queue = [];
            $('.pg-item-cb:checked').each(function() { queue.push($(this).val()); });
            if (queue.length === 0) { alert('No items selected.'); return; }
            done = 0; failed = 0; paused = false; stopped = false;
            updateProgress();
            $('#pg-log').html('');
            log('🚀', 'Starting — ' + queue.length + ' items selected', '#007cba');
            $(this).hide();
            $('#pg-btn-pause, #pg-btn-stop').show();
            processNext();
        });

        $('#pg-btn-pause').on('click', function() {
            paused = true; $(this).hide(); $('#pg-btn-resume').show();
            $('#pg-progress-label').text('⏸ Paused — ' + queue.length + ' remaining');
            log('⏸', 'Paused', '#888');
        });

        $('#pg-btn-resume').on('click', function() {
            paused = false; $(this).hide(); $('#pg-btn-pause').show();
            log('▶', 'Resumed', '#007cba'); processNext();
        });

        $('#pg-btn-stop').on('click', function() {
            if (!confirm('Stop translation?')) return;
            stopped = true; $(this).prop('disabled', true).text('Stopping...');
            $('#pg-btn-pause').hide();
            if (!processing) onFinished();
        });

        $(document).on('click', '.pg-translate-one', function() {
            var postId = $(this).data('id');
            $(this).prop('disabled', true).text('...');
            translateOne(postId, function() {});
        });
    });
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// AJAX — Translate a single page or post
// ─────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_erpgulf_gt_translate_page', 'erpgulf_gt_handle_translate_page' );

function erpgulf_gt_handle_translate_page() {

    if ( ! check_ajax_referer( 'erpgulf_gt_translate_page', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $post_id   = intval( $_POST['post_id'] ?? 0 );
    $post_type = sanitize_key( $_POST['post_type'] ?? 'page' );

    if ( ! $post_id ) wp_send_json_error( 'Invalid ID.' );

    $post = get_post( $post_id );
    if ( ! $post ) wp_send_json_error( 'Post not found.' );

    $registry     = erpgulf_gt_ai_providers();
    $active_key   = erpgulf_gt_active_provider();
    $active_info  = $registry[ $active_key ];
    $translate_fn = 'erpgulf_gt_translate_' . $active_key;

    if ( ! function_exists( $translate_fn ) ) wp_send_json_error( 'Provider not found.' );
    if ( empty( get_option( $active_info['key_option'], '' ) ) ) wp_send_json_error( 'API key not set.' );

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
    $lang_code   = erpgulf_gt_lang_name_to_code( $target_lang );

    $translations = [];
    $fields = [
        'title'   => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
    ];

    foreach ( $fields as $field => $source_text ) {
        if ( empty( trim( $source_text ) ) ) continue;
        $prompt = "Translate the following {$source_lang} text to {$target_lang}. "
                . "Return only the translated text. No explanation. Preserve HTML tags.\n\n{$source_text}";
        $result = $translate_fn( $prompt, $settings );
        if ( ! is_wp_error( $result ) ) {
            $translations[ $field ] = trim( $result );
        }
    }

    if ( empty( $translations ) ) wp_send_json_error( 'Nothing to translate.' );

    $trid       = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
    $en_post_id = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $lang_code );
    $en_status  = $en_post_id ? get_post_status( $en_post_id ) : false;

    if ( $en_post_id && $en_status && $en_status !== 'trash' ) {
        wp_update_post( [
            'ID'           => $en_post_id,
            'post_title'   => $translations['title']   ?? $post->post_title,
            'post_content' => $translations['content'] ?? $post->post_content,
            'post_excerpt' => $translations['excerpt'] ?? '',
        ] );
    } else {
        do_action( 'wpml_switch_language', $lang_code );
        $en_post_id = wp_insert_post( [
            'post_type'    => $post_type,
            'post_status'  => $post->post_status,
            'post_author'  => $post->post_author,
            'post_title'   => $translations['title']   ?? $post->post_title,
            'post_content' => $translations['content'] ?? $post->post_content,
            'post_excerpt' => $translations['excerpt'] ?? '',
        ] );
        do_action( 'wpml_switch_language', ICL_LANGUAGE_CODE );

        if ( is_wp_error( $en_post_id ) ) wp_send_json_error( $en_post_id->get_error_message() );

        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $en_post_id,
            'element_type'         => 'post_' . $post_type,
            'trid'                 => $trid,
            'language_code'        => $lang_code,
            'source_language_code' => ICL_LANGUAGE_CODE,
        ] );
    }

    wp_send_json_success( [
        'en_post_id'  => $en_post_id,
        'en_title'    => $translations['title'] ?? '',
        'en_edit_url' => get_edit_post_link( $en_post_id ),
    ] );
}