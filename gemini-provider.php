<?php
/**
 * AI Provider: Google Gemini
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fetch available Gemini models for the given API key.
 * Returns only models that support generateContent.
 * Cached for 1 hour to avoid repeated API calls on every page load.
 */
function erpgulf_gt_get_gemini_models( string $api_key ): array {

    if ( empty( $api_key ) ) return [];

    // Cache per API key so switching keys still refreshes
    $cache_key = 'erpgulf_gt_gemini_models_' . md5( $api_key );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) return $cached;

    $response = wp_remote_get(
        'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key,
        [ 'timeout' => 15, 'sslverify' => true ]
    );

    if ( is_wp_error( $response ) ) return [];

    $data   = json_decode( wp_remote_retrieve_body( $response ), true );
    $models = $data['models'] ?? [];

    $available = [];
    foreach ( $models as $model ) {
        $methods = $model['supportedGenerationMethods'] ?? [];
        if ( ! in_array( 'generateContent', $methods, true ) ) continue;

        $name             = str_replace( 'models/', '', $model['name'] );
        $label            = $model['displayName'] ?? $name;
        $available[$name] = $label;
    }

    // Cache for 1 hour
    set_transient( $cache_key, $available, HOUR_IN_SECONDS );

    return $available;
}

function erpgulf_gt_translate_gemini( string $prompt, array $settings ): string|WP_Error {

    $api_key = trim( $settings['gemini_api_key'] ?? '' );
    $model   = trim( $settings['gemini_model']   ?? 'gemini-2.0-flash' );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'gemini_no_key', 'Gemini API key is not set.' );
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . $model . ':generateContent?key=' . $api_key;

    $payload = [
        'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
        'generationConfig' => [ 'temperature' => 0.2, 'maxOutputTokens' => 4096 ],
    ];

    $response = wp_remote_post( $url, [
        'timeout'   => 60,
        'sslverify' => true,
        'headers'   => [ 'Content-Type' => 'application/json' ],
        'body'      => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'gemini_http', 'HTTP error: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? "HTTP {$code}";
        return new WP_Error( 'gemini_api', 'Gemini error: ' . $msg );
    }

    $text = trim( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' );

    if ( empty( $text ) ) {
        return new WP_Error( 'gemini_empty', 'Gemini returned an empty response.' );
    }

    return $text;
}