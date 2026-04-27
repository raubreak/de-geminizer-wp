<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base abstracta para providers de IA capaces de eliminar watermarks
 * a través de su API. Todos los providers deben implementar inpaint().
 */
abstract class DGZ_AI_Provider {

    /**
     * @return true|WP_Error
     */
    abstract public function inpaint($file, $position);

    protected function load_image($file, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg($file);
            case IMAGETYPE_PNG:
                $im = @imagecreatefrompng($file);
                if ($im) { imagealphablending($im, true); imagesavealpha($im, true); }
                return $im;
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
            case IMAGETYPE_GIF: return @imagecreatefromgif($file);
        }
        return false;
    }

    protected function save_image($img, $file, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG: return imagejpeg($img, $file, 92);
            case IMAGETYPE_PNG:
                imagesavealpha($img, true);
                return imagepng($img, $file, 6);
            case IMAGETYPE_WEBP:
                return function_exists('imagewebp') ? imagewebp($img, $file, 92) : false;
            case IMAGETYPE_GIF: return imagegif($img, $file);
        }
        return false;
    }

    protected function corner_region($position, $w, $h, $size, $pad) {
        switch ($position) {
            case 'bottom-left': return ['x' => $pad, 'y' => $h - $size - $pad, 'w' => $size, 'h' => $size];
            case 'top-right':   return ['x' => $w - $size - $pad, 'y' => $pad, 'w' => $size, 'h' => $size];
            case 'top-left':    return ['x' => $pad, 'y' => $pad, 'w' => $size, 'h' => $size];
            case 'bottom-right':
            default:            return ['x' => $w - $size - $pad, 'y' => $h - $size - $pad, 'w' => $size, 'h' => $size];
        }
    }

    /**
     * Sustituye el archivo original por la nueva imagen recibida (en bytes),
     * preservando el tipo de archivo original. Redimensiona si la respuesta
     * tiene dimensiones distintas.
     */
    protected function replace_file_with_bytes($file, $orig_w, $orig_h, $orig_type, $bytes) {
        $tmp = wp_tempnam('dgz-ai-result-');
        if (!$tmp) return new WP_Error('dgz_tmp', 'No se pudo crear archivo temporal.');

        $written = @file_put_contents($tmp, $bytes);
        if ($written === false) {
            @unlink($tmp);
            return new WP_Error('dgz_write_tmp', 'No se pudieron escribir los bytes recibidos.');
        }

        $info = @getimagesize($tmp);
        if (!$info) {
            @unlink($tmp);
            return new WP_Error('dgz_decode', 'La respuesta no es una imagen válida.');
        }

        $img = $this->load_image($tmp, $info[2]);
        @unlink($tmp);
        if (!$img) {
            return new WP_Error('dgz_decode2', 'No se pudo decodificar la imagen recibida.');
        }

        // Si la respuesta tiene tamaño distinto, redimensionar al original.
        $rw = imagesx($img); $rh = imagesy($img);
        if ($rw !== $orig_w || $rh !== $orig_h) {
            $resized = imagecreatetruecolor($orig_w, $orig_h);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $orig_w, $orig_h, $rw, $rh);
            imagedestroy($img);
            $img = $resized;
        }

        $saved = $this->save_image($img, $file, $orig_type);
        imagedestroy($img);
        return $saved ? true : new WP_Error('dgz_save', 'No se pudo guardar la imagen final.');
    }
}

/**
 * Provider de Google Gemini usando el modelo de edición de imagen
 * (gemini-2.5-flash-image-preview, "Nano Banana"). Envía la imagen +
 * un prompt textual indicando la esquina con el watermark; el modelo
 * devuelve la imagen editada.
 */
class DGZ_Gemini_Provider extends DGZ_AI_Provider {

    private $key;
    private $model;

    public function __construct($key, $model = 'gemini-2.5-flash-image-preview') {
        $this->key   = (string) $key;
        $this->model = $model ?: 'gemini-2.5-flash-image-preview';
    }

    public function inpaint($file, $position) {
        if (empty($this->key)) {
            return new WP_Error('dgz_no_key', 'Gemini API key no configurada.');
        }

        $info = @getimagesize($file);
        if (!$info) return new WP_Error('dgz_invalid', 'Imagen no válida.');
        list($w, $h, $type) = $info;
        $mime = image_type_to_mime_type($type);

        $bytes = @file_get_contents($file);
        if ($bytes === false) return new WP_Error('dgz_read', 'No se pudo leer el archivo.');

        $position_label = [
            'bottom-right' => 'bottom-right',
            'bottom-left'  => 'bottom-left',
            'top-right'    => 'top-right',
            'top-left'     => 'top-left',
            'auto'         => 'a',
        ][$position] ?? 'a';

        $prompt = sprintf(
            'Remove the small white sparkle (✦) Gemini watermark from the %s corner of this image. Replace those pixels with a seamless continuation of the surrounding background. Keep every other pixel of the image identical: same composition, colors, lighting and subject. Return only the edited image at the same resolution.',
            $position_label
        );

        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->key)
        );

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) return $response;

        $code      = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('dgz_gemini', sprintf('Gemini error %d: %s', $code, substr($resp_body, 0, 400)));
        }

        $data  = json_decode($resp_body, true);
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $img_b64 = null;
        foreach ($parts as $p) {
            if (!empty($p['inlineData']['data'])) { $img_b64 = $p['inlineData']['data']; break; }
            if (!empty($p['inline_data']['data'])) { $img_b64 = $p['inline_data']['data']; break; }
        }
        if (!$img_b64) {
            return new WP_Error('dgz_gemini_response', 'Gemini no devolvió imagen.');
        }

        $img_bytes = base64_decode($img_b64);
        if ($img_bytes === false || strlen($img_bytes) < 100) {
            return new WP_Error('dgz_decode', 'Respuesta de Gemini no decodificable.');
        }

        return $this->replace_file_with_bytes($file, $w, $h, $type, $img_bytes);
    }
}

/**
 * Provider de OpenAI usando el endpoint /v1/images/edits con máscara.
 * Construye un PNG-mask con la zona del watermark transparente y envía
 * imagen + máscara + prompt en multipart/form-data.
 */
class DGZ_OpenAI_Provider extends DGZ_AI_Provider {

    private $key;
    private $model;

    public function __construct($key, $model = 'gpt-image-1') {
        $this->key   = (string) $key;
        $this->model = $model ?: 'gpt-image-1';
    }

    public function inpaint($file, $position) {
        if (empty($this->key)) {
            return new WP_Error('dgz_no_key', 'OpenAI API key no configurada.');
        }

        $info = @getimagesize($file);
        if (!$info) return new WP_Error('dgz_invalid', 'Imagen no válida.');
        list($w, $h, $type) = $info;

        // Para 'auto' la máscara cubre las 4 esquinas (regiones pequeñas).
        $size = max(60, (int) round(min($w, $h) * 0.13));
        $pad  = (int) round($size * 0.06);
        $regions = [];
        if ($position === 'auto') {
            foreach (['bottom-right', 'bottom-left', 'top-right', 'top-left'] as $p) {
                $regions[] = $this->corner_region($p, $w, $h, $size, $pad);
            }
        } else {
            $regions[] = $this->corner_region($position, $w, $h, $size, $pad);
        }

        // 1) Construir PNG de la imagen original (OpenAI requiere PNG).
        $src_img = $this->load_image($file, $type);
        if (!$src_img) return new WP_Error('dgz_load', 'No se pudo cargar la imagen.');

        $tmp_img  = wp_tempnam('dgz-img-')  . '.png';
        $tmp_mask = wp_tempnam('dgz-mask-') . '.png';
        if (!$tmp_img || !$tmp_mask) {
            imagedestroy($src_img);
            return new WP_Error('dgz_tmp', 'No se pudo crear archivo temporal.');
        }
        if (!imagepng($src_img, $tmp_img)) {
            imagedestroy($src_img);
            @unlink($tmp_img); @unlink($tmp_mask);
            return new WP_Error('dgz_write', 'No se pudo escribir PNG temporal.');
        }
        imagedestroy($src_img);

        // 2) Construir la máscara: PNG RGBA donde transparente = "edita aquí",
        //    opaco blanco = "conserva".
        $mask = imagecreatetruecolor($w, $h);
        imagesavealpha($mask, true);
        imagealphablending($mask, false);
        $opaque = imagecolorallocatealpha($mask, 255, 255, 255, 0);
        imagefilledrectangle($mask, 0, 0, $w - 1, $h - 1, $opaque);
        $transparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        foreach ($regions as $r) {
            imagefilledrectangle($mask,
                $r['x'], $r['y'],
                $r['x'] + $r['w'] - 1, $r['y'] + $r['h'] - 1,
                $transparent
            );
        }
        if (!imagepng($mask, $tmp_mask)) {
            imagedestroy($mask);
            @unlink($tmp_img); @unlink($tmp_mask);
            return new WP_Error('dgz_write', 'No se pudo escribir PNG de máscara.');
        }
        imagedestroy($mask);

        // 3) Enviar a OpenAI como multipart.
        $boundary = 'dgz-' . wp_generate_password(24, false, false);
        $body     = $this->build_multipart($boundary, $tmp_img, $tmp_mask);

        $response = wp_remote_post('https://api.openai.com/v1/images/edits', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body'    => $body,
            'timeout' => 120,
        ]);

        @unlink($tmp_img);
        @unlink($tmp_mask);

        if (is_wp_error($response)) return $response;

        $code      = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('dgz_openai', sprintf('OpenAI error %d: %s', $code, substr($resp_body, 0, 400)));
        }

        $data = json_decode($resp_body, true);
        $img_b64 = $data['data'][0]['b64_json'] ?? null;
        $img_url = $data['data'][0]['url']      ?? null;

        if ($img_b64) {
            $img_bytes = base64_decode($img_b64);
        } elseif ($img_url) {
            $r = wp_remote_get($img_url, ['timeout' => 60]);
            if (is_wp_error($r)) return $r;
            $img_bytes = wp_remote_retrieve_body($r);
        } else {
            return new WP_Error('dgz_openai_response', 'OpenAI no devolvió imagen.');
        }

        if (empty($img_bytes) || strlen($img_bytes) < 100) {
            return new WP_Error('dgz_empty', 'Imagen recibida vacía.');
        }

        return $this->replace_file_with_bytes($file, $w, $h, $type, $img_bytes);
    }

    private function build_multipart($boundary, $img_path, $mask_path) {
        $eol = "\r\n";
        $body = '';

        $append_field = function ($name, $value) use (&$body, $boundary, $eol) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        };

        $append_file = function ($name, $filename, $mime, $path) use (&$body, $boundary, $eol) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . $eol;
            $body .= 'Content-Type: ' . $mime . $eol . $eol;
            $body .= file_get_contents($path) . $eol;
        };

        $append_file('image', 'image.png', 'image/png', $img_path);
        $append_file('mask',  'mask.png',  'image/png', $mask_path);
        $append_field('prompt', 'A clean, seamless continuation of the surrounding background. No watermarks, logos, sparkles, stars or text.');
        $append_field('model', $this->model);
        $append_field('n', '1');

        // dall-e-2 requiere size; gpt-image-1 lo infiere.
        if ($this->model === 'dall-e-2') {
            $append_field('size', '1024x1024');
            $append_field('response_format', 'b64_json');
        }

        $body .= '--' . $boundary . '--' . $eol;
        return $body;
    }
}
