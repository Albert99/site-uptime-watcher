# Site Uptime Watcher

Monitor de disponibilidad de URLs externas para WordPress.

## Características
- Comprobación continua cada 5 minutos mediante WP-Cron.
- Sistema anti falsos positivos (espera ciclos fallidos antes de alertar por correo).
- Detección de caídas silenciosas en WordPress:
  - Error Crítico PHP ("Se ha producido un error crítico...").
  - Caída de MySQL ("Error establishing a database connection").
  - White Screen of Death (pantalla en blanco o respuesta vacía).
  - Modo mantenimiento atascado.
- Añadir webs una a una o importación masiva por lote (copiar y pegar).
- Eliminación individual y por lotes.
- Diseño minimalista en lista horizontal con tipografía Outfit.

## Instalación
1. Descarga el ZIP e instálalo desde Plugins > Añadir nuevo > Subir plugin.
2. Activa el plugin en el panel de WordPress.
3. Dirígete a **Uptime Watcher** en el menú lateral.
