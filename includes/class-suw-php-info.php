<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detección y valoración de la versión de PHP de las webs monitorizadas.
 *
 * Detección: la versión solo se conoce si el servidor la publica en las cabeceras
 * HTTP (X-Powered-By: PHP/8.2.12, o "PHP/x.y" dentro de Server). Muchos hostings
 * la ocultan; en ese caso se muestra "sin datos" en lugar de inventarla.
 *
 * Riesgo (según el calendario oficial de php.net):
 *  - alto:  la rama ya no recibe parches de seguridad.
 *  - medio: solo recibe parches de seguridad y le quedan 12 meses o menos.
 *  - bajo:  le quedan más de 12 meses de parches de seguridad.
 */
class Site_Uptime_Php_Info {

    /** Mínimo que recomienda WordPress ("PHP 8.3 o superior"). */
    const RECOMMENDED = '8.3';

    /** Ventana (días) para considerar el riesgo medio. */
    const WARN_DAYS = 365;

    /**
     * Rama => fin del soporte de seguridad (UTC). Ampliable con el filtro
     * `suw_php_support_table` cuando salgan ramas nuevas.
     */
    public static function table() {
        $table = [
            '7.4' => '2022-11-28',
            '8.0' => '2023-11-26',
            '8.1' => '2025-12-31',
            '8.2' => '2026-12-31',
            '8.3' => '2027-12-31',
            '8.4' => '2028-12-31',
            '8.5' => '2029-12-31',
        ];

        $table = apply_filters('suw_php_support_table', $table);
        uksort($table, 'version_compare');

        return $table;
    }

    /**
     * Extrae la versión de PHP de las cabeceras. Devuelve '' si no se publica.
     */
    public static function detect($headers) {
        $haystack = '';
        foreach (['x-powered-by', 'server'] as $key) {
            if (is_array($headers) && !empty($headers[$key])) {
                $haystack .= ' ' . $headers[$key];
            }
        }

        if (preg_match('~\bPHP/(\d+\.\d+(?:\.\d+)?)~i', $haystack, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array{version:string,branch:string,risk:string,label:string,reason:string,recommended:string}
     */
    public static function assess($version) {
        $version     = (string) $version;
        $recommended = self::RECOMMENDED . '+';

        $result = [
            'version'     => $version,
            'branch'      => '',
            'risk'        => 'unknown',
            'label'       => 'Sin datos',
            'reason'      => 'No se conoce la versión de PHP: el servidor no la publica en sus cabeceras.',
            'recommended' => $recommended,
        ];

        if (!preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            return $result;
        }

        $branch           = $m[1] . '.' . $m[2];
        $result['branch'] = $branch;

        $table = self::table();
        $keys  = array_keys($table);
        $first = reset($keys);
        $last  = end($keys);

        if (version_compare($branch, $first, '<')) {
            return self::build($result, 'high', 'PHP ' . $branch . ' hace años que no recibe parches de seguridad.', $recommended);
        }

        if (version_compare($branch, $last, '>')) {
            return self::build($result, 'low', 'PHP ' . $branch . ' es más reciente que la tabla de soporte del plugin.', $recommended);
        }

        if (!isset($table[$branch])) {
            return $result;
        }

        $end  = strtotime($table[$branch] . ' 23:59:59 UTC');
        $days = (int) floor(($end - time()) / DAY_IN_SECONDS);
        $date = gmdate('d/m/Y', $end);

        if ($days < 0) {
            $reason = sprintf('PHP %s no recibe parches de seguridad desde el %s. Las vulnerabilidades nuevas no se corrigen.', $branch, $date);
            return self::build($result, 'high', $reason, $recommended);
        }

        if ($days <= self::WARN_DAYS) {
            $reason = sprintf('PHP %s solo recibe parches de seguridad hasta el %s (quedan %d días).', $branch, $date, $days);
            return self::build($result, 'medium', $reason, $recommended);
        }

        $reason = sprintf('PHP %s recibe parches de seguridad hasta el %s.', $branch, $date);
        return self::build($result, 'low', $reason, $recommended);
    }

    private static function build(array $result, $risk, $reason, $recommended) {
        $labels = ['high' => 'Riesgo alto', 'medium' => 'Riesgo medio', 'low' => 'Riesgo bajo'];

        if (version_compare($result['branch'], self::RECOMMENDED, '<')) {
            $reason .= ' Recomendado: PHP ' . $recommended . '.';
        }

        $result['risk']   = $risk;
        $result['label']  = $labels[$risk];
        $result['reason'] = $reason;

        return $result;
    }
}
