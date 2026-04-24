# De-Geminizer WP

> Elimina el watermark ✦ de Gemini (Google AI) de las imágenes de tu Mediateca con un clic — individual o en bulk — usando **inpainting con máscara**.

| | |
|---|---|
| **Versión** | 1.0.0 |
| **Requiere WordPress** | 5.8+ |
| **Testeado hasta** | 6.5 |
| **Requiere PHP** | 7.4+ (con extensión GD) |
| **Licencia** | GPL-2.0-or-later |
| **Autor** | TransfersPremium |

---

## Descripción

**De-Geminizer WP** añade un botón en el panel de detalles de cada adjunto de imagen para eliminar el pequeño destello (✦) que Gemini y otros modelos de Google AI estampan en la esquina de las imágenes generadas.

A diferencia de herramientas de *clone-stamp* que pegan un parche rectangular y dejan costura visible, este plugin usa un **algoritmo de inpainting con máscara**: detecta únicamente los píxeles del watermark y los rellena con el promedio gaussiano de sus vecinos limpios, propagándose desde el borde hacia el centro (*onion-peel*). El resto de la imagen queda byte-idéntico al original.

## Características

- **Botón "De-Geminizer"** en el panel de detalles de la Mediateca para JPG, PNG, WebP y GIF.
- **Auto-detección** de la esquina con el watermark (escanea las 4 esquinas y elige la de mayor densidad de píxeles candidatos).
- **Selector manual** de esquina: `auto`, `bottom-right`, `bottom-left`, `top-right`, `top-left`.
- **Bulk actions** en la vista de lista de la Mediateca para procesar decenas de imágenes a la vez.
- **Backup automático** del archivo original (`*.dgz-backup.ext`) con botón y bulk action **"Restaurar original"**.
- **Cache-busting** permanente: las URLs de los adjuntos modificados llevan `?dgz=N` para forzar la re-descarga en navegadores y CDN.
- **Regeneración de miniaturas** tras cada operación, con borrado previo de las anteriores para evitar residuos.
- **Inpainting real**: máscara adaptativa + dilatación + fill gaussiano onion-peel + suavizado local.
- **Fallback a clone-stamp** si no detecta ningún watermark (protección contra esquinas uniformemente blancas como cielo).
- **Zero telemetría**: todo el procesado es local con PHP/GD, sin llamadas HTTP externas.

## Casos de uso

- Agencias y sitios que usan Gemini / Imagen / Nano Banana para imágenes destacadas o de producto y necesitan publicarlas limpias.
- Blogs que generan ilustraciones con IA y prefieren un aspecto sin marca.
- Limpieza masiva de una biblioteca ya cargada con imágenes generadas por IA.

> ⚠️ **Nota legal:** usa este plugin únicamente con imágenes que seas legal y contractualmente libre de modificar. Revisa los términos de servicio del proveedor de IA.

---

## Instalación

1. Sube la carpeta `wp-gemini-watermark-remover` a `/wp-content/plugins/`, o instala el ZIP desde **Plugins → Añadir nuevo → Subir plugin**.
2. Activa el plugin en **Plugins** del panel de WordPress.
3. Asegúrate de que PHP tiene la extensión **GD** habilitada (incluida por defecto en XAMPP, MAMP y la mayoría de hostings).
4. Ve a **Mediateca** y abre cualquier imagen — verás el bloque "Watermark Gemini" con el botón **De-Geminizer**.

### Requisitos

- WordPress **5.8+**
- PHP **7.4+**
- Extensión PHP **GD** activa (soporte JPG, PNG, WebP y GIF)
- Capability `upload_files` (por defecto: Editor, Autor, Administrador)

---

## Uso

### Procesado individual

1. Mediateca → clic en cualquier imagen.
2. En el panel derecho verás el bloque **"Watermark Gemini"**.
3. Elige la esquina (o déjalo en **Auto-detectar**).
4. Pulsa **De-Geminizer**.
5. Si el resultado no te convence, pulsa **Restaurar original**.

### Bulk action (lote)

1. Mediateca → cambia a **vista de lista** (icono de lista, arriba a la derecha).
2. Marca las imágenes con los checkboxes.
3. Desplegable **Acciones en lote** → **De-Geminizer (auto)** → **Aplicar**.
4. Al terminar verás un aviso con `N procesadas, M omitidas, K con error`.

> En modo bulk siempre se usa **auto-detección**. Si quieres forzar una esquina concreta, procesa esa imagen individualmente.

---

## Cómo funciona (algoritmo)

El pipeline de eliminación consta de 5 etapas:

| # | Etapa | Descripción |
|---|---|---|
| 1 | **Zona de búsqueda** | Recuadro ~15% del lado menor en la esquina seleccionada (o las 4 esquinas en modo auto). |
| 2 | **Máscara adaptativa** | Selecciona píxeles con `R,G,B > 200`, neutros (`max−min < 45`), y por encima del percentil 90 de brillo local. |
| 3 | **Dilatación** | 3 iteraciones de morfología 8-conexa para atrapar anti-aliasing y glow. |
| 4 | **Onion-peel fill** | Cada pasada rellena los píxeles del borde con promedio gaussiano (σ = radio/2) de vecinos no-marcados; los rellenados sirven de fuente en la siguiente. |
| 5 | **Suavizado local** | Gaussian blur aplicado **solo** sobre los píxeles reparados para fundir transiciones. El resto de la imagen no se toca. |

### Auto-detección

Puntúa cada esquina por número de píxeles compatibles con la máscara y elige la de mayor score, siempre que supere un mínimo de 20 píxeles. Si ninguna supera el umbral, cae al método **clone-stamp** clásico.

---

## FAQ

<details>
<summary><strong>¿Puedo deshacer los cambios?</strong></summary>

Sí. La primera vez que procesas una imagen, el plugin guarda una copia del archivo original junto al actual (`mi-foto.dgz-backup.jpg`). Pulsa **"Restaurar original"** en el panel del adjunto, o usa la bulk action **"Restaurar original (De-Geminizer)"**.
</details>

<details>
<summary><strong>¿Funciona con imágenes grandes?</strong></summary>

Sí. El procesamiento ocurre solo sobre una región ~15% del lado menor (típicamente 150–300 px), por lo que el coste es prácticamente independiente del tamaño total. Una imagen 4K se procesa en menos de un segundo.
</details>

<details>
<summary><strong>¿Afecta a las imágenes ya publicadas en posts?</strong></summary>

No, el archivo modificado conserva el mismo nombre y URL. Las imágenes embebidas se actualizarán automáticamente en cuanto el navegador refresque la caché — el plugin añade `?dgz=N` a todas las URLs de los adjuntos modificados para forzar la re-descarga.
</details>

<details>
<summary><strong>¿Qué formatos soporta?</strong></summary>

JPG, PNG (con transparencia), WebP y GIF. El archivo se guarda en el mismo formato original, preservando alpha en PNG. Para WebP se requiere la extensión GD con `imagewebp`, incluida en PHP 7.4+.
</details>

<details>
<summary><strong>¿Hace llamadas externas?</strong></summary>

No. Todo el procesado es local con PHP/GD. No hay HTTP, telemetría ni envío de datos a ningún servicio.
</details>

<details>
<summary><strong>¿Y si la imagen no tiene watermark?</strong></summary>

El algoritmo necesita un mínimo de 20 píxeles candidatos para considerar que hay watermark. Si no los encuentra, cae al clone-stamp clásico. Si la esquina ya era limpia, restaura desde el backup.
</details>

---

## Para desarrolladores

### Estructura del plugin

```
wp-gemini-watermark-remover/
├── wp-gemini-watermark-remover.php    # Bootstrap del plugin
├── includes/
│   ├── class-plugin.php               # Hooks, AJAX, bulk actions, filtros
│   └── class-watermark-remover.php    # Detección + inpainting GD
├── assets/
│   ├── css/admin.css
│   └── js/media-button.js
└── readme.md
```

### Hooks utilizados

| Hook | Propósito |
|---|---|
| `admin_enqueue_scripts` | Encola JS/CSS del backend. |
| `attachment_fields_to_edit` | Inyecta el bloque "Watermark Gemini" en el panel de detalles. |
| `wp_ajax_dgz_remove_watermark` | Endpoint AJAX de eliminación. |
| `wp_ajax_dgz_restore_original` | Endpoint AJAX de restauración. |
| `bulk_actions-upload` | Registra las bulk actions. |
| `handle_bulk_actions-upload` | Procesa el lote. |
| `wp_get_attachment_url` | Cache-busting con `?dgz=N`. |
| `wp_get_attachment_image_src` | Cache-busting en tags `<img>`. |
| `wp_prepare_attachment_for_js` | Cache-busting en la modal de Mediateca. |
| `admin_notices` | Aviso resumen tras bulk action. |

### Meta keys

| Clave | Tipo | Descripción |
|---|---|---|
| `_dgz_backup_path` | `string` | Ruta absoluta de la copia de seguridad del original. |
| `_dgz_version` | `int` | Contador que se incrementa en cada operación; alimenta `?dgz=N`. |

### Uso programático

```php
// Procesar una imagen desde código
$remover = new DGZ_Watermark_Remover();
$result  = $remover->remove( get_attached_file( $attachment_id ), 'auto' );

if ( is_wp_error( $result ) ) {
    error_log( $result->get_error_message() );
}
```

Posiciones aceptadas: `'auto'`, `'bottom-right'`, `'bottom-left'`, `'top-right'`, `'top-left'`.

---

## Changelog

### 1.0.0

- Lanzamiento inicial.
- Botón **De-Geminizer** en el panel de detalles del adjunto.
- Selector de esquina (auto / 4 posiciones manuales).
- Algoritmo de **inpainting con máscara** (onion-peel gaussiano).
- **Auto-detección** de la esquina con watermark.
- **Backup automático** y botón "Restaurar original".
- **Bulk actions** "De-Geminizer (auto)" y "Restaurar original" en la Mediateca.
- Cache-busting `?dgz=N` en URLs de adjuntos modificados.
- Regeneración de miniaturas con limpieza de residuos.
- **Fallback a clone-stamp** si no se detecta watermark.

---

## Licencia

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) © TransfersPremium
