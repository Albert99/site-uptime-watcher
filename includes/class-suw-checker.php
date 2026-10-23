<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Peticiones HTTP a las webs vigiladas y diagnóstico de la respuesta
 * (errores de red, páginas de error de WordPress, WSOD, códigos HTTP).
 * No guarda estado: recibe URLs y devuelve resultados.
 */
class Site_Uptime_Checker {

    const MAX_BODY        = 262144; // 256 KB de body son suficientes para las firmas
    const ERROR_PAGE_MAX  = 30000;  // las páginas de error de WP son pequeñas
    const TIMEOUT         = 20;     // segundos totales por petición (hostings lentos)
    const CONNECT_TIMEOUT = 10;     // segundos para conectar

    public static function probe(array $urls, array $retryable, $verify, array $ignored = []) {
        $responses = self::fetch_many($urls, $verify);
        $evals     = [];
        $lenient   = [];

        foreach ($urls as $url) {
            $evals[$url] = self::inspect_site_response($responses[$url], $ignored);

            if ($verify && $evals[$url]['is_down'] && self::is_incomplete_chain($responses[$url]['error'])) {
                $lenient[] = $url;
            }
        }

        // Cadena de certificados incompleta: los navegadores la toleran (descargan el
        // intermedio) pero cURL no. Si la web responde sin verificar, está operativa.
        if ($lenient) {
            $second = self::fetch_many($lenient, false);
            foreach ($lenient as $url) {
                $eval = self::inspect_site_response($second[$url], $ignored);
                if (!$eval['is_down']) {
                    $eval['code']  .= ' · SSL incompleto';
                    $eval['detail'] = 'El servidor no envía la cadena completa de certificados. Los navegadores lo toleran, pero conviene corregirlo.';
                }
                $evals[$url] = $eval;
            }
        }

        // Reintento inmediato solo en el primer fallo.
        $failed = [];
        foreach ($urls as $url) {
            if ($evals[$url]['is_down'] && in_array($url, $retryable, true) && !in_array($url, $lenient, true)) {
                $failed[] = $url;
            }
        }

        if ($failed) {
            sleep(2);
            $second = self::fetch_many($failed, $verify);
            foreach ($failed as $url) {
                $evals[$url] = self::inspect_site_response($second[$url], $ignored);
            }
        }

        return $evals;
    }

    private static function is_incomplete_chain($error) {
        if (!is_string($error) || $error === '') {
            return false;
        }

        foreach (['unable to get local issuer certificate', 'unable to get issuer certificate', 'unable to verify the first certificate'] as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Traduce los errores de cURL a una etiqueta corta y legible. */
    private static function friendly_error($message) {
        $message = trim((string) $message);

        if (preg_match('/^(?:cURL error )?(\d+)\b/i', $message, $m)) {
            $map = [
                6  => 'DNS no resuelve',
                7  => 'Conexión rechazada',
                28 => 'Timeout',
                35 => 'Error SSL',
                51 => 'Error SSL',
                52 => 'Respuesta vacía',
                56 => 'Conexión interrumpida',
                58 => 'Error SSL',
                60 => 'Error SSL',
                77 => 'Error SSL',
            ];
            $n = (int) $m[1];
            return isset($map[$n]) ? $map[$n] : 'Error de red (' . $n . ')';
        }

        if (stripos($message, 'timed out') !== false || stripos($message, 'time-out') !== false) {
            return 'Timeout';
        }

        return function_exists('mb_substr') ? mb_substr($message, 0, 40) : substr($message, 0, 40);
    }

    /**
     * GET en paralelo con la librería Requests de WordPress.
     * Devuelve url => ['code' => int, 'body' => string, 'error' => ?string].
     */
    public static function fetch_many(array $urls, $verify) {
        $user_agent = 'Mozilla/5.0 (compatible; SiteUptimeWatcher/' . SUW_VERSION . '; +' . home_url() . ')';
        $out        = [];

        $requests_class = class_exists('\WpOrg\Requests\Requests')
            ? '\WpOrg\Requests\Requests'
            : (class_exists('Requests') ? 'Requests' : '');

        if ($requests_class && method_exists($requests_class, 'request_multiple')) {
            $ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
            $batch     = [];

            foreach ($urls as $url) {
                $batch[$url] = [
                    'url'     => $url,
                    'type'    => 'GET',
                    'headers' => ['User-Agent' => $user_agent],
                    'options' => [
                        'timeout'          => self::TIMEOUT,
                        'connect_timeout'  => self::CONNECT_TIMEOUT,
                        'follow_redirects' => true,
                        'redirects'        => 5,
                        'verify'           => $verify ? (file_exists($ca_bundle) ? $ca_bundle : true) : false,
                        'verifyname'       => (bool) $verify,
                        'max_bytes'        => self::MAX_BODY,
                    ],
                ];
            }

            try {
                $responses = $requests_class::request_multiple($batch);
            } catch (\Throwable $e) {
                $responses = [];
            }

            foreach ($urls as $url) {
                $r = $responses[$url] ?? null;
                if (is_object($r) && isset($r->status_code)) {
                    $out[$url] = [
                        'code'    => (int) $r->status_code,
                        'body'    => substr((string) $r->body, 0, self::MAX_BODY),
                        'error'   => null,
                        'headers' => self::pick_headers(isset($r->headers) ? $r->headers : null),
                    ];
                } else {
                    $out[$url] = [
                        'code'  => 0,
                        'body'  => '',
                        'error' => (is_object($r) && method_exists($r, 'getMessage')) ? $r->getMessage() : 'Sin respuesta',
                    ];
                }
            }

            return $out;
        }

        // Fallback secuencial.
        foreach ($urls as $url) {
            $response = wp_remote_get($url, [
                'timeout'             => self::TIMEOUT,
                'redirection'         => 5,
                'sslverify'           => (bool) $verify,
                'user-agent'          => $user_agent,
                'limit_response_size' => self::MAX_BODY,
            ]);

            if (is_wp_error($response)) {
                $out[$url] = ['code' => 0, 'body' => '', 'error' => $response->get_error_message()];
            } else {
                $out[$url] = [
                    'code'    => (int) wp_remote_retrieve_response_code($response),
                    'body'    => (string) wp_remote_retrieve_body($response),
                    'error'   => null,
                    'headers' => self::pick_headers(wp_remote_retrieve_headers($response)),
                ];
            }
        }

        return $out;
    }

    /** Solo guardamos las dos cabeceras que pueden delatar la versión de PHP. */
    private static function pick_headers($headers) {
        $out = ['x-powered-by' => '', 'server' => ''];

        if (!is_array($headers) && !($headers instanceof ArrayAccess)) {
            return $out;
        }

        foreach (array_keys($out) as $key) {
            $value     = isset($headers[$key]) ? $headers[$key] : '';
            $out[$key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $out;
    }

    private static function inspect_site_response(array $r, array $ignored = []) {
        $eval        = self::inspect_core($r, $ignored);
        $eval['php'] = Site_Uptime_Php_Info::detect(isset($r['headers']) ? $r['headers'] : []);

        return $eval;
    }

    private static function inspect_core(array $r, array $ignored = []) {
        if ($r['error'] !== null) {
            $detail = function_exists('mb_substr') ? mb_substr($r['error'], 0, 160) : substr($r['error'], 0, 160);
            return ['is_down' => true, 'code' => self::friendly_error($r['error']), 'detail' => $detail];
        }

        $http_code = (int) $r['code'];
        $body      = (string) $r['body'];

        // Las firmas solo se buscan en páginas pequeñas: una página de error de WP
        // pesa pocos KB y así un artículo que hable de errores no dispara falsos positivos.
        if ($body !== '' && strlen($body) < self::ERROR_PAGE_MAX) {
            $signatures = [
                'error crítico en este sitio web'               => 'WP Error Crítico PHP',
                'critical error on this website'                => 'WP Critical Error',
                'Error establishing a database connection'      => 'Error MySQL WP',
                'Error al establecer una conexión con la base'  => 'Error MySQL WP',
                'No disponible por mantenimiento programado'    => 'Mantenimiento WP',
                'Briefly unavailable for scheduled maintenance' => 'WP Maintenance',
            ];

            foreach ($signatures as $needle => $label) {
                if (stripos($body, $needle) !== false) {
                    return ['is_down' => true, 'code' => $label];
                }
            }
        }

        if ($http_code === 0) {
            return ['is_down' => true, 'code' => 'Sin respuesta'];
        }

        if ($http_code >= 400) {
            // Código ignorado por configuración (WAF, protección anti-bot...): la web responde.
            return [
                'is_down' => !in_array($http_code, $ignored, true),
                'code'    => 'HTTP ' . $http_code,
            ];
        }

        if (trim($body) === '') {
            return ['is_down' => true, 'code' => 'WP WSOD (Página blanca)'];
        }

        return ['is_down' => false, 'code' => 'HTTP ' . $http_code];
    }

    /** Conectividad básica del servidor: basta con que responda uno de los destinos de control. */
    public static function has_internet() {
        $targets = apply_filters('suw_canary_urls', [
            'https://www.google.com/generate_204',
            'https://www.cloudflare.com/cdn-cgi/trace',
            'https://www.wikipedia.org/',
        ]);

        // Sin verificar certificado: aquí solo importa si hay salida a internet.
        foreach (self::fetch_many((array) $targets, false) as $r) {
            if ($r['error'] === null && $r['code'] > 0 && $r['code'] < 500) {
                return true;
            }
        }

        return false;
    }
}
