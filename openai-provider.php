<?php
/**
 * AI Provider: OpenAI (ChatGPT)
 *
 * ── CONTRACT ─────────────────────────────────────────────────────
 * Function: erpgulf_gt_translate_openai( string $prompt, array $settings ): string|WP_Error
 * Returns:  translated string on success, WP_Error on failure
 *
 * $settings keys used:
 *   openai_api_key   string  OpenAI API key
 *   openai_model     string  Model name (default: gpt-4o-mini)
 * ─────────────────────────────────────────────────────────────────
 *
 * API key: https://platform.openai.com/api-keys
 * Docs:    https://platform.openai.com/docs
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function erpgulf_gt_translate_openai( string $prompt, array $settings ): string|WP_Error {

    $api_key = trim( $settings['openai_api_key'] ?? '' );
    $model   = trim( $settings['openai_model']   ?? 'gpt-4o-mini' );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'openai_no_key', 'OpenAI API key is not set.' );
    }

    $payload = [
        'model'       => $model,
        'temperature' => 0.2,
        'messages'    => [
            [
                'role'    => 'system',
                'content' => 'You are a professional product translator. '
                           . 'Translate the user text exactly as given. '
                           . 'Preserve all HTML tags. '
                           . 'Return only the translated text with no explanation.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ],
    ];

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout'   => 60,
        'sslverify' => true,
        'headers'   => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'openai_http', 'HTTP error: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? "HTTP {$code}";
        return new WP_Error( 'openai_api', 'OpenAI error: ' . $msg );
    }

    $text = trim( $data['choices'][0]['message']['content'] ?? '' );

    if ( empty( $text ) ) {
        return new WP_Error( 'openai_empty', 'OpenAI returned an empty response.' );
    }

    return $text;
}