# Ultimizer

Plugin de WordPress para optimización de imágenes. Comprime JPEG, PNG y GIF, genera versiones AVIF y WebP, elimina metadatos EXIF, guarda respaldos automáticos y permite optimizar toda la biblioteca de forma masiva. Las actualizaciones se distribuyen directamente desde este repositorio.

---

## Características

- **Compresión inteligente** – JPEG progresivo, PNG, GIF y WebP con calidad configurable.
- **Conversión automática** – Genera archivos AVIF y WebP como sidecars sin reemplazar el original.
- **Eliminación de metadatos** – Quita datos EXIF, IPTC y XMP de cada imagen procesada.
- **Respaldos automáticos** – Copia el archivo original antes de optimizarlo. Se puede restaurar desde el panel.
- **Optimización en subida** – Procesa cada imagen automáticamente al agregarla a la biblioteca.
- **Optimización masiva** – Escanea toda la biblioteca y optimiza en lotes con barra de progreso.
- **Frontend adaptativo** – Convierte `<img>` en `<picture>` para servir AVIF o WebP según el navegador.
- **Actualizaciones desde GitHub** – Recibe actualizaciones directamente desde este repositorio en el panel de WordPress.

---

## Requisitos

- WordPress 5.8 o superior
- PHP 7.4 o superior
- Extensión **Imagick** (recomendado) o **GD** para conversión AVIF/WebP

---

## Instalación

1. Descarga el `.zip` desde la sección [Releases](https://github.com/kerackdiaz/ultimizer/releases).
2. En WordPress, ve a **Plugins → Añadir nuevo → Subir plugin**.
3. Sube el `.zip` y activa el plugin.
4. Accede a **Ultimizer** en el menú lateral para configurarlo.

### Actualización automática

El plugin verifica nuevas versiones desde GitHub Releases. Cuando haya una actualización disponible, aparecerá en **Plugins → Actualizaciones** igual que cualquier plugin del repositorio oficial.

---

## Uso

### Panel principal

Muestra estadísticas globales: imágenes totales, optimizadas, pendientes y espacio recuperado.  
Desde aquí puedes **escanear** la biblioteca para ver el estado de cada imagen y luego **iniciar la optimización masiva**.

### Configuración

Ajusta la calidad de compresión para cada formato:

| Formato | Rango | Recomendado |
|---------|-------|-------------|
| JPEG    | 1–100 | 80–85       |
| PNG     | 0–9   | 6           |
| WebP    | 1–100 | 78–82       |
| AVIF    | 1–100 | 60–70       |

### Registro

Historial de todas las imágenes optimizadas con tamaño antes/después y ahorro obtenido.

### Respaldos

Lista de archivos originales guardados antes de optimizar. Permite restaurar cualquier imagen a su estado previo o eliminar respaldos para liberar espacio en disco.


---

## Licencia

GPL-2.0+  
Autor: [Kerack Diaz](https://github.com/kerackdiaz)

