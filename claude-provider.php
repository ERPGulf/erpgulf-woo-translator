<?php
/**
 * AI Provider: Anthropic Claude
 *
 * ── CONTRACT ─────────────────────────────────────────────────────
 * Function: erpgulf_gt_translate_claude( string $prompt, array $settings ): string|WP_Error
 * Returns:  translated string on success, WP_Error on failure
 *
 * $settings keys used:
 *   claude_api_key   string  Anthropic API key
 *   claude_model     string  Model name (default: claude-haiku-4-5-20251001)
 * ─────────────────────────────────────────────────────────────────
 *
 * API key: https://console.anthropic.com/settings/keys
 * Docs:    https://docs.anthropic.com/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function erpgulf_gt_translate_claude( string $prompt, array $settings ): string|WP_Error {

    $api_key = trim( $settings['claude_api_key'] ?? '' );
    $model   = trim( $settings['claude_model']   ?? 'claude-haiku-4-5-20251001' );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'claude_no_key', 'Claude API key is not set.' );
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => 4096,
        'system'     => 'You are a professional product translator. '
                      . 'Translate the user text exactly as given. '
                      . 'Preserve all HTML tags. '
                      . 'Return only the translated text with no explanation or preamble.',
        'messages'   => [
            [ 'role' => 'user', 'content' => $prompt ],
        ],
    ];

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout'   => 60,
        'sslverify' => true,
        'headers'   => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'claude_http', 'HTTP error: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? "HTTP {$code}";
        return new WP_Error( 'claude_api', 'Claude error: ' . $msg );
    }

    $text = trim( $data['content'][0]['text'] ?? '' );

    if ( empty( $text ) ) {
        return new WP_Error( 'claude_empty', 'Claude returned an empty response.' );
    }

    return $text;
}