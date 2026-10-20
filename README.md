# Site Uptime Watcher

Monitor de disponibilidad de URLs externas para WordPress.

## Características
- Comprobación cada 5 minutos mediante WP-Cron, en lotes paralelos (20 webs a la vez).
- Sistema anti falsos positivos:
  - Reconfirmación inmediata del primer fallo de cada web.
  - Estado intermedio "Verificando n/N" hasta alcanzar el umbral configurado.
  - Bloqueo para que dos ciclos nunca se solapen.
  - Si el correo de alerta falla, se reintenta en el siguiente ciclo.
- Detección de caídas silenciosas en WordPress:
  - Error Crítico PHP ("Se ha producido un error crítico...").
  - Caída de MySQL ("Error establishing a database connection").
  - White Screen of Death (pantalla en blanco o respuesta vacía).
  - Modo mantenimiento atascado.
- Verificación de certificado SSL activada por defecto (se puede desactivar en Ajustes).
- Códigos HTTP ignorables por configuración e historial de incidentes.
- Avisos por correo en HTML con diseño limpio y duración de la caída.
- Añadir webs una a una o importación masiva por lote (copiar y pegar).
- Eliminación individual y por lotes.
- Diseño minimalista en lista horizontal con tipografía Outfit incluida en local (sin llamadas a Google).

## Instalación
1. Descarga el ZIP e instálalo desde Plugins > Añadir nuevo > Subir plugin.
2. Activa el plugin en el panel de WordPress.
3. Dirígete a **Uptime Watcher** en el menú lateral.

## Actualizaciones
El plugin se actualiza solo desde este repositorio de GitHub, con el sistema nativo de WordPress:
- Aparece el aviso "Hay una nueva versión" en Plugins y en Escritorio > Actualizaciones, y se puede activar la actualización automática.
- En la propia pantalla de **Uptime Watcher** hay un botón *Buscar actualizaciones* y, si hay versión nueva, un banner con *Actualizar ahora*.
- En la lista de Plugins hay un enlace *Buscar actualizaciones* y, si procede, *Actualizar ahora*.

Para publicar una versión nueva:
1. Sube `Version:` en `site-uptime-watcher.php` y `SUW_VERSION`, y súbelo a `main`.
2. Recomendado: crea una Release en GitHub con tag `vX.Y.Z` (y, opcionalmente, adjunta `site-uptime-watcher.zip`).
   Sin Release, el plugin compara la versión de la cabecera en `main`.

La consulta se cachea 6 horas. Funciona con repositorio público.

## Recomendación de producción
WP-Cron solo se dispara con visitas. Para comprobaciones exactas cada 5 minutos, desactívalo y usa un cron del servidor:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```
*/5 * * * * curl -s https://tu-sitio.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Un monitor instalado en un sitio no puede avisar de su propia caída. Instálalo en una web distinta de las que vigilas.

## Estructura
```
site-uptime-watcher/
├── site-uptime-watcher.php
├── uninstall.php
├── includes/class-site-uptime-watcher.php
├── includes/class-suw-updater.php
└── assets/
    ├── css/admin.css
    ├── js/admin.js
    └── fonts/   (Outfit, licencia SIL OFL 1.1)
```

## Changelog

### 1.9.1
- Corrige falsos positivos en webs operativas:
  - Certificado con cadena incompleta (error SSL 60): si la web responde sin verificar, se marca como operativa con la nota "SSL incompleto". Los certificados caducados o de otro dominio siguen contando como caída.
  - Tiempos de espera más generosos (20 s en total, 10 s para conectar) para hostings lentos.
- Errores de red con etiqueta legible (Timeout, Error SSL, DNS no resuelve...) y el detalle completo al pasar el ratón.
- Las webs en cola se comprueban en cuanto es posible: la importación tiene presupuesto de tiempo, lo pendiente se programa a los segundos y cada ciclo empieza por las más antiguas.

### 1.9.0
- Selector *Ordenar* en la lista: por estado (caídas primero), nombre A → Z o nombre Z → A. Se aplica al instante y recuerda la última elección.

### 1.8.1
- No se añaden webs que ya están en la lista. La comparación ignora `http`/`https`, `www.`, mayúsculas y la barra final, y el aviso indica cuántas se omitieron.

### 1.8.0
- Correos de caída y recuperación en HTML (compatibles con Gmail, Outlook y móvil) con versión de texto alternativa.
- El correo de recuperación indica cuánto tiempo estuvo caída la web.
- Botón *Enviar correo de prueba* en Ajustes.

### 1.7.0
- Actualizador desde GitHub integrado en WordPress, con botón *Buscar actualizaciones*, banner *Actualizar ahora* y enlaces en la lista de Plugins.

### 1.6.0
- Nuevo ajuste "Códigos HTTP a ignorar" (por ejemplo 403, 429) para webs tras un WAF o protección anti-bot que responden operativas.
- Historial de incidentes (caídas y recuperaciones, últimos 100) con los 8 más recientes en el panel lateral.

### 1.5.0
- Corrige la programación del cron: en la activación el intervalo `five_minutes` no estaba registrado, por lo que el evento podía no programarse nunca. Ahora se autorrepara en `init`.
- Un fallo ya no marca la web como "Caída" hasta alcanzar el umbral (nuevo estado "Verificando").
- Comprobaciones en paralelo con `Requests::request_multiple`, body limitado a 256 KB y reconfirmación inmediata del primer fallo.
- Lock con transient contra ciclos solapados; estado releído tras cada lote para no resucitar webs borradas.
- Las firmas de error de WordPress solo se buscan en páginas pequeñas (evita falsos positivos en artículos que hablen de esos errores).
- Formularios con `admin-post.php` y redirección (sin reenvío al refrescar); ajustes sanitizados y sin campo oculto con la lista.
- Verificación SSL activa por defecto, con opción para desactivarla.
- Sin web de ejemplo por defecto; webs ordenadas con las caídas primero.
- Outfit en local y avatar con inicial en lugar del servicio de favicons de Google.
- Indicador de próxima comprobación y aviso si el cron no está programado.
- Corregido el doble `confirm` al borrar una web individual.
- Añadido `uninstall.php`.

### 1.4.0
- Versión anterior.
