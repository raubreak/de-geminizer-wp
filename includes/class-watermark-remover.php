<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elimina el watermark de Gemini usando inpainting con máscara:
 *  1) Localiza una zona de búsqueda en la esquina indicada.
 *  2) Construye una máscara con los píxeles claros (el destello ✦).
 *  3) Dilata la máscara para atrapar bordes suaves/glow.
 *  4) Rellena cada píxel enmascarado con el promedio gaussiano de los
 *     vecinos no enmascarados (onion-peel, varias pasadas desde el borde).
 *  5) Suaviza ligeramente solo la zona reparada.
 *
 * Si no se detectan píxeles compatibles con el watermark, se recurre al
 * método clone-stamp clásico como fallback.
 */
class DGZ_Watermark_Remover {

    public function remove($file, $position = 'bottom-right') {
        if (!extension_loaded('gd')) {
            return new WP_Error('dgz_no_gd', __('La extensión GD de PHP no está disponible.', 'wp-gemini-watermark-remover'));
        }

        $info = @getimagesize($file);
        if (!$info) {
            return new WP_Error('dgz_invalid', __('No se pudo leer la imagen.', 'wp-gemini-watermark-remover'));
        }

        list($width, $height, $type) = $info;

        $img = $this->load_image($file, $type);
        if (!$img) {
            return new WP_Error('dgz_load', __('Formato de imagen no soportado o corrupto.', 'wp-gemini-watermark-remover'));
        }

        // Zona de búsqueda amplia (~15% del lado menor) para localizar el destello
        // aunque varíe ligeramente de tamaño/posición.
        $bbox_size = max(80, (int) round(min($width, $height) * 0.15));
        $pad       = (int) round($bbox_size * 0.03);

        if ($position === 'auto') {
            $detected = $this->detect_corner($img, $width, $height, $bbox_size, $pad);
            $position = $detected ?: 'bottom-right';
        }

        $bbox = $this->region_for_position($position, $width, $height, $bbox_size, $pad);

        $used_inpaint = $this->inpaint_watermark($img, $bbox);

        if (!$used_inpaint) {
            // Fallback: clone-stamp sobre una zona pequeña (~8% del lado menor).
            $wm_size   = max(48, (int) round(min($width, $height) * 0.08));
            $wm_pad    = (int) round($wm_size * 0.25);
            $region    = $this->region_for_position($position, $width, $height, $wm_size, $wm_pad);
            $this->clone_stamp($img, $region, $width, $height);
        }

        $saved = $this->save_image($img, $file, $type);
        imagedestroy($img);

        if (!$saved) {
            return new WP_Error('dgz_save', __('No se pudo guardar la imagen.', 'wp-gemini-watermark-remover'));
        }

        return true;
    }

    /* ---------- I/O ---------- */

    private function load_image($file, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($file);
            case IMAGETYPE_PNG:
                $im = @imagecreatefrompng($file);
                if ($im) {
                    imagealphablending($im, true);
                    imagesavealpha($im, true);
                }
                return $im;
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
            case IMAGETYPE_GIF:
                return @imagecreatefromgif($file);
        }
        return false;
    }

    private function save_image($img, $file, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagejpeg($img, $file, 92);
            case IMAGETYPE_PNG:
                imagesavealpha($img, true);
                return imagepng($img, $file, 6);
            case IMAGETYPE_WEBP:
                return function_exists('imagewebp') ? imagewebp($img, $file, 92) : false;
            case IMAGETYPE_GIF:
                return imagegif($img, $file);
        }
        return false;
    }

    private function region_for_position($position, $w, $h, $size, $pad) {
        switch ($position) {
            case 'bottom-left':
                return ['x' => $pad, 'y' => $h - $size - $pad, 'w' => $size, 'h' => $size];
            case 'top-right':
                return ['x' => $w - $size - $pad, 'y' => $pad, 'w' => $size, 'h' => $size];
            case 'top-left':
                return ['x' => $pad, 'y' => $pad, 'w' => $size, 'h' => $size];
            case 'bottom-right':
            default:
                return ['x' => $w - $size - $pad, 'y' => $h - $size - $pad, 'w' => $size, 'h' => $size];
        }
    }

    /* ---------- Inpainting ---------- */

    /**
     * Evalúa las 4 esquinas y devuelve la que presenta mayor densidad de píxeles
     * compatibles con el destello de Gemini. Devuelve null si ninguna supera un
     * mínimo razonable.
     */
    public function detect_corner($img, $width, $height, $bbox_size = null, $pad = null) {
        if ($bbox_size === null) $bbox_size = max(80, (int) round(min($width, $height) * 0.15));
        if ($pad === null)       $pad       = (int) round($bbox_size * 0.03);

        $best_pos = null; $best_score = 0;
        foreach (['bottom-right', 'bottom-left', 'top-right', 'top-left'] as $pos) {
            $bbox  = $this->region_for_position($pos, $width, $height, $bbox_size, $pad);
            $score = $this->score_bbox($img, $bbox);
            if ($score > $best_score) {
                $best_score = $score;
                $best_pos   = $pos;
            }
        }
        return $best_score >= 20 ? $best_pos : null;
    }

    private function score_bbox($img, $bbox) {
        $r = []; $g = []; $b = [];
        $mask = $this->build_mask($img, $bbox, $r, $g, $b);
        return array_sum($mask);
    }

    /**
     * Construye la máscara binaria del watermark en el bbox dado y devuelve
     * por referencia los arrays de canales R/G/B para su uso en inpainting.
     */
    private function build_mask($img, $bbox, &$r, &$g, &$b) {
        $bx = $bbox['x']; $by = $bbox['y']; $bw = $bbox['w']; $bh = $bbox['h'];

        $r = []; $g = []; $b = []; $bright = [];
        for ($y = 0; $y < $bh; $y++) {
            for ($x = 0; $x < $bw; $x++) {
                $rgb = imagecolorat($img, $bx + $x, $by + $y);
                $rr  = ($rgb >> 16) & 0xFF;
                $gg  = ($rgb >> 8) & 0xFF;
                $bb  = $rgb & 0xFF;
                $idx = $y * $bw + $x;
                $r[$idx] = $rr; $g[$idx] = $gg; $b[$idx] = $bb;
                $bright[$idx] = ($rr + $gg + $bb) / 3;
            }
        }

        $sorted = $bright;
        sort($sorted);
        $n = count($sorted);
        $p90 = $sorted[(int) floor($n * 0.90)];
        $p50 = $sorted[(int) floor($n * 0.50)];
        $bright_thr = max(210, $p90);
        $delta_thr  = max(30, $p90 - $p50);

        $mask  = array_fill(0, $bw * $bh, 0);
        $count = 0;
        for ($i = 0; $i < $bw * $bh; $i++) {
            $rr = $r[$i]; $gg = $g[$i]; $bb = $b[$i];
            $maxc = max($rr, $gg, $bb);
            $minc = min($rr, $gg, $bb);
            $isWhiteish = $rr > 200 && $gg > 200 && $bb > 200 && ($maxc - $minc) < 45;
            $isBright   = $bright[$i] >= $bright_thr && $bright[$i] >= $p50 + $delta_thr;
            if ($isWhiteish && $isBright) {
                $mask[$i] = 1;
                $count++;
            }
        }

        // No consideramos máscara válida una esquina uniformemente blanca.
        if ($count > ($bw * $bh) * 0.35) {
            return array_fill(0, $bw * $bh, 0);
        }
        return $mask;
    }

    /**
     * @return bool  true si detectó y rellenó el watermark; false si no hay nada compatible.
     */
    private function inpaint_watermark($img, $bbox) {
        $bx = $bbox['x']; $by = $bbox['y'];
        $bw = $bbox['w']; $bh = $bbox['h'];

        $r = []; $g = []; $b = [];
        $mask = $this->build_mask($img, $bbox, $r, $g, $b);
        $count = array_sum($mask);
        if ($count < 20) {
            return false;
        }

        // 3) Dilatar la máscara para atrapar glow/antialiasing.
        $mask = $this->dilate_mask($mask, $bw, $bh, 3);

        // 4) Onion-peel inpainting: rellenar píxeles que tengan vecinos no-máscara,
        //    iterar hasta cerrar. Cada pasada usa los píxeles ya rellenados de la anterior.
        $this->inpaint_onion_peel($r, $g, $b, $mask, $bw, $bh, 6);

        // 5) Volcar los píxeles rellenados al lienzo.
        for ($y = 0; $y < $bh; $y++) {
            for ($x = 0; $x < $bw; $x++) {
                $i = $y * $bw + $x;
                if (empty($mask[$i])) continue; // no tocado
                $color = imagecolorallocate($img, $r[$i], $g[$i], $b[$i]);
                imagesetpixel($img, $bx + $x, $by + $y, $color);
            }
        }

        // 6) Suavizado muy ligero solo sobre la región reparada para fundir transiciones.
        $this->local_smooth($img, $bx, $by, $bw, $bh, $mask, 1);

        return true;
    }

    private function dilate_mask($mask, $w, $h, $iterations) {
        for ($it = 0; $it < $iterations; $it++) {
            $out = array_fill(0, $w * $h, 0);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $i = $y * $w + $x;
                    if ($mask[$i]) { $out[$i] = 1; continue; }
                    $hit = 0;
                    for ($dy = -1; $dy <= 1 && !$hit; $dy++) {
                        for ($dx = -1; $dx <= 1 && !$hit; $dx++) {
                            $ny = $y + $dy; $nx = $x + $dx;
                            if ($ny < 0 || $ny >= $h || $nx < 0 || $nx >= $w) continue;
                            if ($mask[$ny * $w + $nx]) $hit = 1;
                        }
                    }
                    $out[$i] = $hit;
                }
            }
            $mask = $out;
        }
        return $mask;
    }

    /**
     * Onion-peel: en cada pasada, para cada píxel enmascarado, promedia con Gaussiano
     * los vecinos *no* enmascarados dentro de un radio. Los píxeles rellenados se
     * desmarcan tras la pasada, permitiendo que en la siguiente sirvan de fuente.
     * Resultado: el relleno se propaga desde el borde hacia el centro con gradiente suave.
     */
    private function inpaint_onion_peel(&$r, &$g, &$b, &$mask, $w, $h, $radius) {
        $sigma2 = 2 * ($radius / 2.0) * ($radius / 2.0);

        $max_passes = 25;
        for ($pass = 0; $pass < $max_passes; $pass++) {
            $to_fill = [];
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $i = $y * $w + $x;
                    if (!$mask[$i]) continue;

                    // Solo tratamos píxeles de borde (con al menos un vecino 8-conexo no enmascarado).
                    $has_free_neighbor = false;
                    for ($dy = -1; $dy <= 1 && !$has_free_neighbor; $dy++) {
                        for ($dx = -1; $dx <= 1 && !$has_free_neighbor; $dx++) {
                            if ($dx === 0 && $dy === 0) continue;
                            $ny = $y + $dy; $nx = $x + $dx;
                            if ($ny < 0 || $ny >= $h || $nx < 0 || $nx >= $w) continue;
                            if (!$mask[$ny * $w + $nx]) $has_free_neighbor = true;
                        }
                    }
                    if (!$has_free_neighbor) continue;

                    $sumR = 0; $sumG = 0; $sumB = 0; $sumW = 0;
                    for ($dy = -$radius; $dy <= $radius; $dy++) {
                        for ($dx = -$radius; $dx <= $radius; $dx++) {
                            $ny = $y + $dy; $nx = $x + $dx;
                            if ($ny < 0 || $ny >= $h || $nx < 0 || $nx >= $w) continue;
                            $j = $ny * $w + $nx;
                            if ($mask[$j]) continue;
                            $d2 = $dx * $dx + $dy * $dy;
                            if ($d2 > $radius * $radius) continue;
                            $wgt = exp(-$d2 / $sigma2);
                            $sumR += $r[$j] * $wgt;
                            $sumG += $g[$j] * $wgt;
                            $sumB += $b[$j] * $wgt;
                            $sumW += $wgt;
                        }
                    }
                    if ($sumW > 0) {
                        $to_fill[] = [
                            $i,
                            (int) round($sumR / $sumW),
                            (int) round($sumG / $sumW),
                            (int) round($sumB / $sumW),
                        ];
                    }
                }
            }

            if (empty($to_fill)) break;

            foreach ($to_fill as $f) {
                list($idx, $rv, $gv, $bv) = $f;
                $r[$idx] = max(0, min(255, $rv));
                $g[$idx] = max(0, min(255, $gv));
                $b[$idx] = max(0, min(255, $bv));
                $mask[$idx] = 2; // filled este pase (todavía no disponible como fuente)
            }
            // Liberar los recién rellenados para que sirvan de fuente en el próximo pase.
            for ($i = 0, $total = $w * $h; $i < $total; $i++) {
                if ($mask[$i] === 2) $mask[$i] = 0;
            }
        }

        // Por consistencia, asegurar que cualquier píxel aún marcado quede a 1
        // para que el volcado posterior lo toque (ya con su promedio final).
        // (En la práctica, con 25 pases suficientes, no debería quedar ninguno).
        for ($i = 0, $total = $w * $h; $i < $total; $i++) {
            if ($mask[$i] === 1) $mask[$i] = 1; // no-op, explícito
        }
    }

    /**
     * Aplica un gaussian blur solo sobre el bbox, y copia de vuelta únicamente
     * los píxeles originalmente enmascarados. Así los píxeles ajenos al watermark
     * permanecen idénticos al original.
     */
    private function local_smooth($img, $bx, $by, $bw, $bh, $mask, $passes) {
        if ($passes <= 0 || !function_exists('imagefilter')) return;

        $sub = imagecreatetruecolor($bw, $bh);
        imagealphablending($sub, false);
        imagesavealpha($sub, true);
        imagecopy($sub, $img, 0, 0, $bx, $by, $bw, $bh);
        for ($i = 0; $i < $passes; $i++) {
            @imagefilter($sub, IMG_FILTER_GAUSSIAN_BLUR);
        }

        for ($y = 0; $y < $bh; $y++) {
            for ($x = 0; $x < $bw; $x++) {
                $i = $y * $bw + $x;
                if (empty($mask[$i])) continue;
                $rgb = imagecolorat($sub, $x, $y);
                $rr = ($rgb >> 16) & 0xFF;
                $gg = ($rgb >> 8) & 0xFF;
                $bb = $rgb & 0xFF;
                $color = imagecolorallocate($img, $rr, $gg, $bb);
                imagesetpixel($img, $bx + $x, $by + $y, $color);
            }
        }
        imagedestroy($sub);
    }

    /* ---------- Fallback: clone-stamp ---------- */

    private function clone_stamp($img, $region, $img_w, $img_h) {
        $x  = $region['x']; $y  = $region['y'];
        $rw = $region['w']; $rh = $region['h'];
        $gap = 4;

        $candidates = [
            ['x' => $x, 'y' => $y - $rh - $gap],
            ['x' => $x, 'y' => $y + $rh + $gap],
            ['x' => $x - $rw - $gap, 'y' => $y],
            ['x' => $x + $rw + $gap, 'y' => $y],
        ];
        $src_x = $x; $src_y = max(0, $y - $rh - $gap);
        foreach ($candidates as $c) {
            if ($c['x'] >= 0 && $c['y'] >= 0
                && $c['x'] + $rw <= $img_w
                && $c['y'] + $rh <= $img_h) {
                $src_x = $c['x']; $src_y = $c['y']; break;
            }
        }
        imagecopy($img, $img, $x, $y, $src_x, $src_y, $rw, $rh);
    }
}
