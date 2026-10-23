<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Correos de aviso en HTML (tablas + estilos en línea) con versión de texto alternativa.
 */
class Site_Uptime_Mailer {

    private static function host_of($url) {
        return preg_replace('#^www\.#i', '', (string) (parse_url($url, PHP_URL_HOST) ?: $url));
    }

    private static function send_html_mail($to, $subject, $html, $text) {
        $alt = function ($phpmailer) use ($text) {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $alt);
        $sent = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $alt);

        return (bool) $sent;
    }

    public static function send_down($url, $code, $recipient, $failures = 0, $max = 0, $first_ts = 0, $detail = '') {
        $host = self::host_of($url);
        $rows = ['Diagnóstico' => $code, 'Detectada' => current_time('mysql')];

        if ($detail !== '') {
            $rows['Detalle'] = $detail;
        }

        if ($first_ts > 0) {
            $rows['Primer fallo'] = wp_date('Y-m-d H:i:s', $first_ts);
        }
        if ($failures > 0) {
            $rows['Comprobaciones fallidas'] = $max > 0 ? sprintf('%d seguidas (umbral: %d)', $failures, $max) : (string) $failures;
        }

        $mail = self::render_email([
            'kind'      => 'down',
            'badge'     => 'CAÍDA CONFIRMADA',
            'host'      => $host,
            'url'       => $url,
            'intro'     => 'La web no responde correctamente. El aviso se envía tras reconfirmar el fallo varias veces para evitar falsas alarmas.',
            'rows'      => $rows,
            'preheader' => $host . ' no responde: ' . $code,
        ]);

        return self::send_html_mail($recipient, '🔴 [ALERTA] Web caída: ' . $host, $mail['html'], $mail['text']);
    }

    public static function send_recovery($url, $recipient, $first_ts = 0, $code = '') {
        $host = self::host_of($url);
        $rows = ['Estado' => 'Operativa', 'Recuperada' => current_time('mysql')];

        if ($first_ts > 0) {
            $rows['Tiempo sin servicio'] = human_time_diff($first_ts, time());
        }
        if ($code !== '') {
            $rows['Respuesta'] = $code;
        }

        $mail = self::render_email([
            'kind'      => 'up',
            'badge'     => 'RECUPERADA',
            'host'      => $host,
            'url'       => $url,
            'intro'     => 'La web vuelve a responder con normalidad.',
            'rows'      => $rows,
            'preheader' => $host . ' vuelve a estar operativa',
        ]);

        return self::send_html_mail($recipient, '🟢 [RECUPERADA] Web online: ' . $host, $mail['html'], $mail['text']);
    }

    /**
     * Un único correo para varios avisos del mismo ciclo (evita 40 correos si cae un hosting entero).
     */
    public static function send_digest($kind, array $items, $recipient) {
        $is_up     = ($kind === 'up');
        $is_remind = ($kind === 'remind');
        $count     = count($items);
        $rows      = [];

        foreach (array_slice($items, 0, 40) as $item) {
            $label = self::host_of($item['url']);
            if (isset($rows[$label])) {
                $label = $item['url']; // misma web con y sin www
            }
            $value = $item['code'];
            if ($is_remind && !empty($item['first_ts'])) {
                $value .= ' · ' . human_time_diff((int) $item['first_ts'], time());
            }
            $rows[$label] = $value;
        }
        if ($count > 40) {
            $rows['…'] = sprintf('y %d más', $count - 40);
        }

        if ($is_up) {
            $badge = 'RECUPERADAS';
            $host  = sprintf('%d webs recuperadas', $count);
            $intro = 'Estas webs vuelven a responder con normalidad.';
            $pre   = sprintf('%d webs vuelven a estar operativas', $count);
            $subj  = sprintf('🟢 [RECUPERADAS] %d webs online', $count);
        } elseif ($is_remind) {
            $badge = 'SIGUEN CAÍDAS';
            $host  = sprintf('%d webs siguen caídas', $count);
            $intro = 'Recordatorio: estas webs continúan sin responder correctamente. No se volverá a avisar hasta pasadas 24 horas o hasta que se recuperen.';
            $pre   = sprintf('%d webs siguen sin responder', $count);
            $subj  = sprintf('🔴 [SIGUEN CAÍDAS] %d webs', $count);
        } else {
            $badge = 'VARIAS CAÍDAS';
            $host  = sprintf('%d webs caídas', $count);
            $intro = 'Varias webs han fallado a la vez y todas superaron la reconfirmación. Si comparten hosting o proveedor, la causa suele ser común.';
            $pre   = sprintf('%d webs no responden', $count);
            $subj  = sprintf('🔴 [ALERTA] %d webs caídas', $count);
        }

        $mail = self::render_email([
            'kind'      => $is_up ? 'up' : 'down',
            'badge'     => $badge,
            'host'      => $host,
            'url'       => '',
            'intro'     => $intro,
            'rows'      => $rows,
            'preheader' => $pre,
        ]);

        return self::send_html_mail($recipient, $subj, $mail['html'], $mail['text']);
    }

    /** Recordatorio de una caída que sigue abierta (el aviso inicial ya se envió). */
    public static function send_reminder($url, $code, $recipient, $first_ts = 0, $detail = '') {
        $host = self::host_of($url);
        $rows = ['Diagnóstico' => $code];

        if ($detail !== '') {
            $rows['Detalle'] = $detail;
        }
        if ($first_ts > 0) {
            $rows['Caída desde']         = wp_date('Y-m-d H:i:s', $first_ts);
            $rows['Tiempo sin servicio'] = human_time_diff($first_ts, time());
        }

        $mail = self::render_email([
            'kind'      => 'down',
            'badge'     => 'SIGUE CAÍDA',
            'host'      => $host,
            'url'       => $url,
            'intro'     => 'Esta web continúa sin responder correctamente. Es un recordatorio: no se volverá a avisar hasta pasadas 24 horas o hasta que se recupere.',
            'rows'      => $rows,
            'preheader' => $host . ' sigue caída' . ($first_ts > 0 ? ' desde hace ' . human_time_diff($first_ts, time()) : ''),
        ]);

        return self::send_html_mail($recipient, '🔴 [SIGUE CAÍDA] ' . $host . ($first_ts > 0 ? ' · ' . human_time_diff($first_ts, time()) : ''), $mail['html'], $mail['text']);
    }

    /**
     * Plantilla basada en tablas y estilos en línea para que se vea igual en Gmail,
     * Outlook y clientes móviles. Devuelve ['html' => ..., 'text' => ...].
     */
    private static function render_email(array $a) {
        $is_down = ($a['kind'] === 'down');
        $accent  = $is_down ? '#ea4335' : '#34a853';
        $soft    = $is_down ? '#fce8e6' : '#e6f4ea';
        $ink     = $is_down ? '#c5221f' : '#137333';
        $panel   = admin_url('admin.php?page=suw-settings');
        $site    = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $font    = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        $rows_html = '';
        $text      = strip_tags($a['badge']) . ' - ' . $a['host'] . "\n" . ($a['url'] !== '' ? $a['url'] . "\n" : '') . "\n" . $a['intro'] . "\n\n";

        foreach ($a['rows'] as $label => $value) {
            $rows_html .= '<tr>'
                . '<td style="padding:11px 0;border-bottom:1px solid #edeef2;font-size:13px;color:#5f6368;">' . esc_html($label) . '</td>'
                . '<td align="right" style="padding:11px 0;border-bottom:1px solid #edeef2;font-size:14px;font-weight:600;color:#1f1f1f;">' . esc_html($value) . '</td>'
                . '</tr>';
            $text .= $label . ': ' . $value . "\n";
        }
        $text .= "\nPanel: " . $panel . "\n";

        $btn = 'display:inline-block;font-family:' . $font . ';font-size:14px;font-weight:600;text-decoration:none;padding:12px 22px;border-radius:999px;';

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . esc_html($a['host']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f3f4;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html($a['preheader']) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f3f4;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;font-family:' . $font . ';">'

            . '<tr><td style="padding:0 6px 14px 6px;font-size:13px;font-weight:700;letter-spacing:.02em;color:#5f6368;">Uptime Watcher</td></tr>'

            . '<tr><td style="background:#ffffff;border:1px solid #e3e3e8;border-radius:20px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'

            . '<tr><td style="background:' . $soft . ';border-top-left-radius:20px;border-top-right-radius:20px;border-bottom:4px solid ' . $accent . ';padding:28px 28px 24px 28px;">'
            . '<span style="display:inline-block;background:' . $accent . ';color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.06em;padding:6px 12px;border-radius:999px;">' . esc_html($a['badge']) . '</span>'
            . '<div style="font-size:28px;line-height:1.2;font-weight:700;color:#1f1f1f;margin-top:16px;word-break:break-word;">' . esc_html($a['host']) . '</div>'
            . ($a['url'] !== '' ? '<div style="margin-top:6px;font-size:13px;word-break:break-all;"><a href="' . esc_url($a['url']) . '" style="color:' . $ink . ';text-decoration:none;">' . esc_html($a['url']) . '</a></div>' : '')
            . '</td></tr>'

            . '<tr><td style="padding:24px 28px 8px 28px;font-size:15px;line-height:1.55;color:#3c4043;">' . esc_html($a['intro']) . '</td></tr>'

            . '<tr><td style="padding:8px 28px 4px 28px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows_html . '</table>'
            . '</td></tr>'

            . '<tr><td style="padding:22px 28px 30px 28px;">'
            . ($a['url'] !== '' ? '<a href="' . esc_url($a['url']) . '" style="' . $btn . 'background:#1a73e8;color:#ffffff;">Abrir la web</a>&nbsp;&nbsp;' : '')
            . '<a href="' . esc_url($panel) . '" style="' . $btn . 'background:#f1f3f4;color:#1f1f1f;">Ver panel</a>'
            . '</td></tr>'

            . '</table></td></tr>'

            . '<tr><td align="center" style="padding:18px 8px 0 8px;font-size:12px;line-height:1.5;color:#80868b;">'
            . 'Aviso automático enviado por Site Uptime Watcher desde ' . esc_html($site) . '.'
            . '</td></tr>'

            . '</table></td></tr></table></body></html>';

        return ['html' => $html, 'text' => $text];
    }
}
