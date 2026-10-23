<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vista del panel. Se incluye desde Site_Uptime_Watcher::admin_page_html(),
 * que prepara las variables: $notice, $update, $opts, $rows, $max, $post, $has_php,
 * $total_up, $total_down, $total_pending, $total_paused, $next_run, $last_run,
 * $is_stale, $guard.
 */
?>
        <div class="suw-container">

            <header class="suw-header-bar">
                <div class="suw-header-text">
                    <h1>Uptime Watcher</h1>
                    <p>Monitor de estado y caídas de sitios web.</p>
                </div>
                <div class="suw-header-actions">
                    <a href="<?php echo esc_url(Site_Uptime_Updater::check_url()); ?>" class="suw-btn suw-btn-tonal" title="Consultar si hay una versión nueva en GitHub">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>Buscar actualizaciones <em class="suw-version-chip">v<?php echo esc_html(SUW_VERSION); ?></em></span>
                    </a>
                    <button type="button" class="suw-btn suw-btn-tonal" id="suw-btn-open-bulk">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span>Subir muchas webs</span>
                    </button>

                    <form method="post" action="<?php echo $post; ?>" id="suw-manual-check-form" style="margin:0;">
                        <?php wp_nonce_field('suw_manual_check'); ?>
                        <input type="hidden" name="action" value="suw_manual_check">
                        <button type="submit" class="suw-btn suw-btn-tonal" id="suw-btn-check">
                            <svg class="suw-svg suw-sync-target" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                            <span>Comprobar ahora</span>
                        </button>
                    </form>
                </div>
            </header>

            <?php if ($update): ?>
                <div class="suw-update-banner">
                    <div class="suw-update-text">
                        <strong>Nueva versión disponible: v<?php echo esc_html($update['version']); ?></strong>
                        <span>Tienes instalada la v<?php echo esc_html(SUW_VERSION); ?>. La actualización conserva tus webs y ajustes.</span>
                    </div>
                    <div class="suw-update-actions">
                        <a href="<?php echo esc_url($update['url']); ?>" target="_blank" rel="noopener noreferrer" class="suw-btn suw-btn-tonal">Ver novedades</a>
                        <a href="<?php echo esc_url(Site_Uptime_Updater::update_url()); ?>" class="suw-btn suw-btn-primary">Actualizar ahora</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="suw-toast <?php echo $notice[1] === 'error' ? 'is-error' : 'is-success'; ?>">
                    <span><?php echo esc_html($notice[0]); ?></span>
                </div>
            <?php endif; ?>

            <div class="suw-modal" id="suw-bulk-modal" style="display: none;">
                <div class="suw-modal-backdrop"></div>
                <div class="suw-modal-dialog">
                    <div class="suw-modal-head">
                        <h3>Subir lote de webs</h3>
                        <button type="button" class="suw-modal-close" id="suw-btn-close-bulk">&times;</button>
                    </div>
                    <form method="post" action="<?php echo $post; ?>">
                        <?php wp_nonce_field('suw_bulk_add'); ?>
                        <input type="hidden" name="action" value="suw_bulk_add">

                        <p class="suw-modal-hint">Copia y pega la lista de webs aquí (una por línea, o separadas por comas). Si falta <code>https://</code> se pondrá automáticamente.</p>

                        <textarea name="suw_bulk_urls" class="suw-modal-textarea" rows="8" placeholder="midominio1.es&#10;https://tiendaonline.com&#10;cliente3.org" required></textarea>

                        <div class="suw-modal-foot">
                            <button type="button" class="suw-btn suw-btn-tonal" id="suw-btn-cancel-bulk">Cancelar</button>
                            <button type="submit" class="suw-btn suw-btn-primary">Añadir todas y verificar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="suw-add-bar">
                <form method="post" action="<?php echo $post; ?>" class="suw-add-form">
                    <?php wp_nonce_field('suw_add_site'); ?>
                    <input type="hidden" name="action" value="suw_add_site">
                    <div class="suw-input-wrap">
                        <svg class="suw-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                        <input type="text" name="suw_new_url" class="suw-input-url" placeholder="https://ejemplo.com o ejemplo.com" required autocomplete="off">
                    </div>
                    <button type="submit" class="suw-btn suw-btn-primary">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Añadir web</span>
                    </button>
                </form>
            </div>

            <div class="suw-filter-tabs">
                <button type="button" class="suw-pill-tab is-active" data-filter="all">
                    <span>Todos los sitios</span>
                    <span class="suw-pill-chip"><?php echo (int) count($rows); ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="up">
                    <span class="suw-dot is-up"></span>
                    <span>Operativos</span>
                    <span class="suw-pill-chip is-up"><?php echo (int) $total_up; ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="down">
                    <span class="suw-dot is-down"></span>
                    <span>Caídos</span>
                    <span class="suw-pill-chip is-down"><?php echo (int) $total_down; ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="pending">
                    <span class="suw-dot is-pending"></span>
                    <span>Verificando</span>
                    <span class="suw-pill-chip is-pending"><?php echo (int) $total_pending; ?></span>
                </button>
                <?php if ($total_paused > 0): ?>
                <button type="button" class="suw-pill-tab" data-filter="paused">
                    <span class="suw-dot is-paused"></span>
                    <span>En pausa</span>
                    <span class="suw-pill-chip is-paused"><?php echo (int) $total_paused; ?></span>
                </button>
                <?php endif; ?>
            </div>

            <div class="suw-layout">
                <div class="suw-main-col">
                    <form method="post" action="<?php echo $post; ?>" id="suw-bulk-form">
                        <?php wp_nonce_field('suw_delete_sites'); ?>
                        <input type="hidden" name="action" value="suw_delete_sites">

                        <div class="suw-list-toolbar">
                            <label class="suw-select-all-label">
                                <input type="checkbox" id="suw-select-all" class="suw-check">
                                <span>Seleccionar todo</span>
                            </label>
                            <label class="suw-sort">
                                <span>Ordenar</span>
                                <select id="suw-sort" class="suw-control suw-select suw-sort-select">
                                    <option value="status">Estado (caídas primero)</option>
                                    <option value="name-asc">Nombre A → Z</option>
                                    <option value="name-desc">Nombre Z → A</option>
                                </select>
                            </label>
                            <button type="submit" id="suw-btn-delete-selected" class="suw-btn suw-btn-danger" disabled>
                                <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span>Eliminar seleccionadas</span>
                            </button>
                        </div>

                        <div class="suw-list-stack">
                            <?php if (empty($rows)): ?>
                                <div class="suw-empty-card">
                                    <p>No hay webs agregadas en la lista.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($rows as $row_index => $row):
                                    $url      = $row['url'];
                                    $host     = $row['host'];
                                    $status   = $row['status'];
                                    $data     = $row['data'];
                                    $failures = (int) ($data['consecutive_failures'] ?? 0);
                                    $queued   = ($status === 'pending' && $failures === 0);
                                    $code     = !empty($data['last_code']) ? $data['last_code'] : '—';
                                    $initial  = function_exists('mb_substr') ? mb_strtoupper(mb_substr($host, 0, 1)) : strtoupper(substr($host, 0, 1));
                                    $code_cls = $status === 'up' ? 'is-good' : ($status === 'down' ? 'is-bad' : 'is-wait');
                                    $php      = Site_Uptime_Php_Info::assess(isset($data['php_version']) ? $data['php_version'] : '');
                                ?>
                                    <div class="suw-row-card is-<?php echo esc_attr($status); ?>" data-status="<?php echo esc_attr($status); ?>" data-host="<?php echo esc_attr(strtolower($host)); ?>" data-order="<?php echo (int) $row_index; ?>">
                                        <div class="suw-row-select">
                                            <input type="checkbox" name="selected_urls[]" value="<?php echo esc_attr($url); ?>" class="suw-check suw-site-checkbox">
                                        </div>

                                        <div class="suw-row-favicon" aria-hidden="true">
                                            <span class="suw-row-initial"><?php echo esc_html($initial); ?></span>
                                        </div>

                                        <div class="suw-row-site">
                                            <div class="suw-row-title">
                                                <span class="suw-host-name"><?php echo esc_html($host); ?></span>
                                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" class="suw-ext-link">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                                </a>
                                            </div>
                                            <span class="suw-full-url"><?php echo esc_html($url); ?></span>
                                        </div>

                                        <div class="suw-row-status">
                                            <?php if ($status === 'up'): ?>
                                                <span class="suw-badge is-up"><span class="suw-dot is-up"></span>Online</span>
                                            <?php elseif ($status === 'down'): ?>
                                                <span class="suw-badge is-down"><span class="suw-dot is-down"></span>Caída</span>
                                            <?php elseif ($status === 'paused'): ?>
                                                <span class="suw-badge is-paused"><span class="suw-dot is-paused"></span>En pausa</span>
                                            <?php elseif ($queued): ?>
                                                <span class="suw-badge is-pending"><span class="suw-dot is-pending"></span>En cola</span>
                                            <?php else: ?>
                                                <span class="suw-badge is-pending"><span class="suw-dot is-pending"></span>Verificando <?php echo (int) $failures; ?>/<?php echo (int) $max; ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="suw-row-code">
                                            <span class="suw-code-tag <?php echo esc_attr($code_cls); ?>" title="<?php echo esc_attr(trim($code . ' ' . (isset($data['last_detail']) ? $data['last_detail'] : ''))); ?>"><?php echo esc_html($code); ?></span>
                                        </div>

                                        <?php if ($has_php): ?>
                                        <div class="suw-row-php">
                                            <?php if ($php['version'] !== ''): ?>
                                                <span class="suw-php-version">PHP <?php echo esc_html($php['version']); ?></span>
                                            <?php else: ?>
                                                <span class="suw-php-version is-empty" title="<?php echo esc_attr($php['reason']); ?>">PHP —</span>
                                            <?php endif; ?>
                                            <span class="suw-php-rec">Recomendada <?php echo esc_html($php['recommended']); ?></span>
                                        </div>

                                        <div class="suw-row-risk">
                                            <span class="suw-risk is-<?php echo esc_attr($php['risk']); ?>" title="<?php echo esc_attr($php['reason']); ?>"><?php echo esc_html($php['label']); ?></span>
                                        </div>

                                        <?php endif; ?>

                                        <div class="suw-row-meta">
                                            <span class="suw-meta-failures">Fallos: <strong><?php echo (int) $failures; ?></strong></span>
                                            <span class="suw-meta-time"><?php echo !empty($data['last_check']) ? esc_html($data['last_check']) : '—'; ?></span>
                                        </div>

                                        <div class="suw-row-actions">
                                            <?php $pause_url = wp_nonce_url(admin_url('admin-post.php?action=suw_toggle_pause&url=' . rawurlencode($url)), 'suw_toggle_pause'); ?>
                                            <a href="<?php echo esc_url($pause_url); ?>" class="suw-icon-btn" title="<?php echo $status === 'paused' ? 'Reanudar vigilancia' : 'Pausar vigilancia'; ?>">
                                                <?php if ($status === 'paused'): ?>
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="6 4 20 12 6 20 6 4"></polygon></svg>
                                                <?php else: ?>
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="5" x2="8" y2="19"></line><line x1="16" y1="5" x2="16" y2="19"></line></svg>
                                                <?php endif; ?>
                                            </a>
                                            <button type="button" class="suw-icon-btn suw-delete-single" data-url="<?php echo esc_attr($url); ?>" title="Eliminar web">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="suw-side-col">
                    <div class="suw-settings-box">
                        <h3>Ajustes</h3>
                        <p class="suw-settings-desc">Alertas por email y sensibilidad de corte.</p>

                        <form method="post" action="<?php echo $post; ?>">
                            <?php wp_nonce_field('suw_save_settings'); ?>
                            <input type="hidden" name="action" value="suw_save_settings">

                            <div class="suw-field">
                                <label for="suw_email">Email de alerta</label>
                                <input type="email" id="suw_email" name="notify_email" value="<?php echo esc_attr($opts['notify_email']); ?>" class="suw-control" required>
                                <small>Receptor de avisos de corte y recuperación.</small>
                            </div>

                            <div class="suw-field">
                                <label for="suw_max_failures">Sensibilidad anti falsos positivos</label>
                                <select id="suw_max_failures" name="max_failures" class="suw-control suw-select">
                                    <?php foreach ([2 => 10, 3 => 15, 4 => 20, 5 => 25] as $n => $minutes): ?>
                                        <option value="<?php echo (int) $n; ?>" <?php selected($max, $n); ?>><?php echo (int) $n; ?> comprobaciones (<?php echo (int) $minutes; ?> min)</option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Fallos consecutivos antes de enviar el correo. Cada primer fallo se reconfirma al instante.</small>
                            </div>

                            <div class="suw-field suw-field-check">
                                <label for="suw_verify_ssl">
                                    <input type="checkbox" id="suw_verify_ssl" name="verify_ssl" value="1" class="suw-check" <?php checked(!empty($opts['verify_ssl'])); ?>>
                                    <span>Verificar certificado SSL</span>
                                </label>
                                <small>Recomendado: un certificado caducado cuenta como caída para el visitante.</small>
                            </div>

                            <div class="suw-field">
                                <label for="suw_ignored_codes">Códigos HTTP a ignorar</label>
                                <input type="text" id="suw_ignored_codes" name="ignored_codes" value="<?php echo esc_attr($opts['ignored_codes']); ?>" class="suw-control" placeholder="403, 429">
                                <small>Opcional. Útil si una web está tras un WAF que bloquea bots: respondería 403 estando operativa.</small>
                            </div>

                            <div class="suw-field">
                                <label for="suw_heartbeat">URL de heartbeat</label>
                                <input type="url" id="suw_heartbeat" name="heartbeat_url" value="<?php echo esc_attr($opts['heartbeat_url']); ?>" class="suw-control" placeholder="https://hc-ping.com/…">
                                <small>Opcional. Se llama tras cada ciclo. En un servicio externo (healthchecks.io, UptimeRobot) te avisa si el monitor deja de comprobar.</small>
                            </div>

                            <button type="submit" class="suw-btn suw-btn-primary suw-btn-full">
                                Guardar preferencias
                            </button>
                        </form>

                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=suw_test_email'), 'suw_test_email')); ?>" class="suw-btn suw-btn-tonal suw-btn-full">
                            Enviar correo de prueba
                        </a>

                        <p class="suw-cron-status">
                            <?php if ($last_run): ?>
                                Última comprobación:
                                <strong class="<?php echo $is_stale ? 'suw-cron-warn' : ''; ?>">hace <?php echo esc_html(human_time_diff($last_run, time())); ?></strong><br>
                            <?php endif; ?>

                            <?php if ($is_stale): ?>
                                <span class="suw-cron-warn">El monitor lleva demasiado sin comprobar. Revisa WP-Cron o usa un cron real del servidor.</span><br>
                            <?php endif; ?>

                            <?php if ($guard): ?>
                                <span class="suw-cron-warn">Hace <?php echo esc_html(human_time_diff((int) $guard['time'], time())); ?> se descartó un ciclo: fallaron <?php echo (int) $guard['failed']; ?> de <?php echo (int) $guard['total']; ?> webs y este servidor no tenía salida a internet. No se enviaron alertas.</span><br>
                            <?php endif; ?>

                            <?php if ($next_run): ?>
                                Próxima comprobación: <strong><?php echo esc_html(wp_date('H:i:s', $next_run)); ?></strong>
                            <?php else: ?>
                                <strong class="suw-cron-warn">WP-Cron no está programado.</strong> Recarga la página o reactiva el plugin.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php $incidents = array_slice((array) get_option('suw_incidents', []), 0, 8); ?>
                    <div class="suw-settings-box suw-incidents-box">
                        <h3>Últimos incidentes</h3>
                        <?php if (empty($incidents)): ?>
                            <p class="suw-settings-desc">Sin incidentes registrados.</p>
                        <?php else: ?>
                            <ul class="suw-incident-list">
                                <?php foreach ($incidents as $inc):
                                    $is_down = ($inc['type'] ?? '') === 'down';
                                    $inc_host = preg_replace('#^www\.#i', '', (string) (parse_url($inc['url'] ?? '', PHP_URL_HOST) ?: ($inc['url'] ?? '')));
                                ?>
                                    <li class="suw-incident <?php echo $is_down ? 'is-down' : 'is-up'; ?>">
                                        <span class="suw-dot <?php echo $is_down ? 'is-down' : 'is-up'; ?>"></span>
                                        <span class="suw-incident-text">
                                            <strong><?php echo esc_html($inc_host); ?></strong>
                                            <?php echo $is_down ? 'cayó' : 'recuperada'; ?> · <?php echo esc_html($inc['code'] ?? ''); ?>
                                            <em><?php echo esc_html($inc['time'] ?? ''); ?></em>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
