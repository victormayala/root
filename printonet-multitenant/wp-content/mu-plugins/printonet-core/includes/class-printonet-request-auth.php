<?php

if (!defined('ABSPATH')) {
    exit;
}

class Printonet_Request_Auth
{
    private const REPLAY_TTL_SECONDS = 300;

    public static function verify_platform_signature(WP_REST_Request $request): bool
    {
        $secret = defined('PRINTONET_PLATFORM_HMAC_SECRET')
            ? (string) PRINTONET_PLATFORM_HMAC_SECRET
            : '';
        if ($secret === '') {
            return false;
        }

        $timestamp = (string) $request->get_header('x-printonet-timestamp');
        $signature = (string) $request->get_header('x-printonet-signature');
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }

        $issued_at = (int) $timestamp;
        if (abs(time() - $issued_at) > 300) {
            return false;
        }

        $body = (string) $request->get_body();
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        if (self::is_replay($request, $timestamp, $signature)) {
            return false;
        }

        return true;
    }

    private static function is_replay(WP_REST_Request $request, string $timestamp, string $signature): bool
    {
        $method = strtoupper((string) $request->get_method());
        $route = (string) $request->get_route();
        $key = 'printonet_sig_' . md5($method . '|' . $route . '|' . $timestamp . '|' . $signature);

        if (get_site_transient($key)) {
            return true;
        }

        set_site_transient($key, 1, self::REPLAY_TTL_SECONDS);
        return false;
    }
}
