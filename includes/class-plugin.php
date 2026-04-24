<?php
if (!defined('ABSPATH')) {
    exit;
}

class DGZ_Plugin {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_enqueue_scripts',   [$this, 'enqueue_admin_assets']);
        add_filter('attachment_fields_to_edit', [$this, 'add_attachment_field'], 10, 2);
        add_action('wp_ajax_dgz_remove_watermark', [$this, 'ajax_remove_watermark']);
        add_action('wp_ajax_dgz_restore_original', [$this, 'ajax_restore_original']);

        // Cache-busting: añade ?dgz=N a las URLs de los adjuntos modificados
        // para que el navegador vuelva a descargar la imagen/miniaturas.
        add_filter('wp_get_attachment_url',       [$this, 'filter_attachment_url'], 10, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'filter_image_src'], 10, 2);
        add_filter('wp_prepare_attachment_for_js', [$this, 'filter_js_attachment'], 10, 2);

        // Bulk actions en la mediateca (vista de lista: upload.php).
        add_filter('bulk_actions-upload',        [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices',              [$this, 'bulk_admin_notices']);
    }

    public function enqueue_admin_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // Load everywhere in admin because the media modal can be opened from many screens.
        wp_enqueue_script(
            'dgz-media-button',
            DGZ_PLUGIN_URL . 'assets/js/media-button.js',
            ['jquery'],
            DGZ_VERSION,
            true
        );
        wp_localize_script('dgz-media-button', 'DGZ', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('dgz_nonce'),
            'i18n'    => [
                'processing' => __('Procesando…', 'wp-gemini-watermark-remover'),
                'success'    => __('Watermark eliminado ✓', 'wp-gemini-watermark-remover'),
                'restored'   => __('Original restaurado ✓', 'wp-gemini-watermark-remover'),
                'error'      => __('Error al procesar la imagen', 'wp-gemini-watermark-remover'),
            ],
        ]);

        wp_enqueue_style(
            'dgz-admin',
            DGZ_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DGZ_VERSION
        );
    }

    public function add_attachment_field($form_fields, $post) {
        if (empty($post->ID) || !wp_attachment_is_image($post->ID)) {
            return $form_fields;
        }

        $has_backup = (bool) get_post_meta($post->ID, '_dgz_backup_path', true);

        ob_start();
        ?>
        <div class="dgz-wrap" data-attachment-id="<?php echo esc_attr($post->ID); ?>">
            <select class="dgz-position-select">
                <option value="auto"><?php esc_html_e('Auto-detectar', 'wp-gemini-watermark-remover'); ?></option>
                <option value="bottom-right"><?php esc_html_e('Abajo derecha', 'wp-gemini-watermark-remover'); ?></option>
                <option value="bottom-left"><?php esc_html_e('Abajo izquierda', 'wp-gemini-watermark-remover'); ?></option>
                <option value="top-right"><?php esc_html_e('Arriba derecha', 'wp-gemini-watermark-remover'); ?></option>
                <option value="top-left"><?php esc_html_e('Arriba izquierda', 'wp-gemini-watermark-remover'); ?></option>
            </select>
            <button type="button" class="button button-primary dgz-remove-btn">
                <span class="dashicons dashicons-star-filled"></span>
                <?php esc_html_e('De-Geminizer', 'wp-gemini-watermark-remover'); ?>
            </button>
            <button type="button" class="button dgz-restore-btn" <?php disabled(!$has_backup); ?>>
                <?php esc_html_e('Restaurar original', 'wp-gemini-watermark-remover'); ?>
            </button>
            <span class="dgz-status" aria-live="polite"></span>
        </div>
        <?php
        $html = ob_get_clean();

        $form_fields['dgz_remove'] = [
            'label' => __('Watermark Gemini', 'wp-gemini-watermark-remover'),
            'input' => 'html',
            'html'  => $html,
            'helps' => __('Elimina el destello ✦ de Gemini de la esquina seleccionada. Se guarda una copia del original por si quieres revertir.', 'wp-gemini-watermark-remover'),
        ];

        return $form_fields;
    }

    public function ajax_remove_watermark() {
        check_ajax_referer('dgz_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permisos insuficientes.', 'wp-gemini-watermark-remover')], 403);
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $position      = isset($_POST['position']) ? sanitize_key($_POST['position']) : 'bottom-right';

        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            wp_send_json_error(['message' => __('Adjunto inválido.', 'wp-gemini-watermark-remover')], 400);
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            wp_send_json_error(['message' => __('Archivo no encontrado.', 'wp-gemini-watermark-remover')], 404);
        }

        // Backup original once (before first modification).
        $backup_path = get_post_meta($attachment_id, '_dgz_backup_path', true);
        if (!$backup_path || !file_exists($backup_path)) {
            $backup_path = $this->make_backup_path($file);
            if (!@copy($file, $backup_path)) {
                wp_send_json_error(['message' => __('No se pudo crear la copia de seguridad.', 'wp-gemini-watermark-remover')], 500);
            }
            update_post_meta($attachment_id, '_dgz_backup_path', $backup_path);
        }

        $remover = new DGZ_Watermark_Remover();
        $result  = $remover->remove($file, $position);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        $this->bump_version($attachment_id);
        $this->regenerate_metadata($attachment_id, $file);

        wp_send_json_success([
            'message' => __('Watermark eliminado.', 'wp-gemini-watermark-remover'),
            'url'     => add_query_arg('t', time(), wp_get_attachment_url($attachment_id)),
        ]);
    }

    public function ajax_restore_original() {
        check_ajax_referer('dgz_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permisos insuficientes.', 'wp-gemini-watermark-remover')], 403);
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            wp_send_json_error(['message' => __('Adjunto inválido.', 'wp-gemini-watermark-remover')], 400);
        }

        $backup_path = get_post_meta($attachment_id, '_dgz_backup_path', true);
        $file        = get_attached_file($attachment_id);

        if (!$backup_path || !file_exists($backup_path) || !$file) {
            wp_send_json_error(['message' => __('No hay copia de seguridad.', 'wp-gemini-watermark-remover')], 404);
        }

        if (!@copy($backup_path, $file)) {
            wp_send_json_error(['message' => __('No se pudo restaurar el original.', 'wp-gemini-watermark-remover')], 500);
        }

        @unlink($backup_path);
        delete_post_meta($attachment_id, '_dgz_backup_path');

        $this->bump_version($attachment_id);
        $this->regenerate_metadata($attachment_id, $file);

        wp_send_json_success([
            'message' => __('Original restaurado.', 'wp-gemini-watermark-remover'),
            'url'     => add_query_arg('t', time(), wp_get_attachment_url($attachment_id)),
        ]);
    }

    private function make_backup_path($file) {
        $info = pathinfo($file);
        $dir  = $info['dirname'];
        $name = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
        return $dir . '/' . $name . '.dgz-backup' . $ext;
    }

    private function regenerate_metadata($attachment_id, $file) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Borrar miniaturas existentes antes de regenerar para asegurar que los
        // nuevos archivos reflejan la imagen modificada (evita residuos).
        $old_meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($old_meta['sizes']) && is_array($old_meta['sizes'])) {
            $base_dir = trailingslashit(dirname($file));
            foreach ($old_meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb = $base_dir . $size['file'];
                    if (file_exists($thumb) && $thumb !== $file) {
                        @unlink($thumb);
                    }
                }
            }
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $file);
        if (!empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        // Tocar post_modified para invalidar caches/CDN que lo consideren.
        wp_update_post([
            'ID'                => $attachment_id,
            'post_modified'     => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);
    }

    private function bump_version($attachment_id) {
        $v = (int) get_post_meta($attachment_id, '_dgz_version', true);
        update_post_meta($attachment_id, '_dgz_version', $v + 1);
    }

    public function filter_attachment_url($url, $attachment_id) {
        $v = (int) get_post_meta($attachment_id, '_dgz_version', true);
        if ($v > 0 && is_string($url) && $url !== '') {
            $url = add_query_arg('dgz', $v, $url);
        }
        return $url;
    }

    public function filter_image_src($image, $attachment_id) {
        if (is_array($image) && !empty($image[0])) {
            $v = (int) get_post_meta($attachment_id, '_dgz_version', true);
            if ($v > 0) {
                $image[0] = add_query_arg('dgz', $v, $image[0]);
            }
        }
        return $image;
    }

    /* ---------- Bulk actions ---------- */

    public function register_bulk_actions($actions) {
        $actions['dgz_remove']  = __('De-Geminizer (auto)', 'wp-gemini-watermark-remover');
        $actions['dgz_restore'] = __('Restaurar original (De-Geminizer)', 'wp-gemini-watermark-remover');
        return $actions;
    }

    public function handle_bulk_actions($redirect_url, $action, $ids) {
        if (!in_array($action, ['dgz_remove', 'dgz_restore'], true)) {
            return $redirect_url;
        }
        if (!current_user_can('upload_files')) {
            return $redirect_url;
        }

        $ok = 0; $skipped = 0; $failed = 0;
        @set_time_limit(0);

        foreach ((array) $ids as $attachment_id) {
            $attachment_id = absint($attachment_id);
            if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
                $skipped++;
                continue;
            }

            $file = get_attached_file($attachment_id);
            if (!$file || !file_exists($file)) {
                $failed++;
                continue;
            }

            if ($action === 'dgz_remove') {
                // Crear backup una única vez.
                $backup_path = get_post_meta($attachment_id, '_dgz_backup_path', true);
                if (!$backup_path || !file_exists($backup_path)) {
                    $backup_path = $this->make_backup_path($file);
                    if (!@copy($file, $backup_path)) {
                        $failed++;
                        continue;
                    }
                    update_post_meta($attachment_id, '_dgz_backup_path', $backup_path);
                }

                $remover = new DGZ_Watermark_Remover();
                $result  = $remover->remove($file, 'auto');
                if (is_wp_error($result)) {
                    $failed++;
                    continue;
                }
                $this->bump_version($attachment_id);
                $this->regenerate_metadata($attachment_id, $file);
                $ok++;
            } else { // dgz_restore
                $backup_path = get_post_meta($attachment_id, '_dgz_backup_path', true);
                if (!$backup_path || !file_exists($backup_path)) {
                    $skipped++;
                    continue;
                }
                if (!@copy($backup_path, $file)) {
                    $failed++;
                    continue;
                }
                @unlink($backup_path);
                delete_post_meta($attachment_id, '_dgz_backup_path');
                $this->bump_version($attachment_id);
                $this->regenerate_metadata($attachment_id, $file);
                $ok++;
            }
        }

        $redirect_url = add_query_arg([
            'dgz_bulk'    => $action,
            'dgz_ok'      => $ok,
            'dgz_skipped' => $skipped,
            'dgz_failed'  => $failed,
        ], $redirect_url);

        return $redirect_url;
    }

    public function bulk_admin_notices() {
        if (empty($_GET['dgz_bulk'])) return;
        $action  = sanitize_key(wp_unslash($_GET['dgz_bulk']));
        $ok      = isset($_GET['dgz_ok']) ? (int) $_GET['dgz_ok'] : 0;
        $skipped = isset($_GET['dgz_skipped']) ? (int) $_GET['dgz_skipped'] : 0;
        $failed  = isset($_GET['dgz_failed']) ? (int) $_GET['dgz_failed'] : 0;

        $class = $failed > 0 ? 'notice-warning' : 'notice-success';
        $label = ($action === 'dgz_restore')
            ? __('Restauración De-Geminizer', 'wp-gemini-watermark-remover')
            : __('De-Geminizer', 'wp-gemini-watermark-remover');

        $msg = sprintf(
            /* translators: 1: action label, 2: ok, 3: skipped, 4: failed */
            __('%1$s: %2$d procesadas, %3$d omitidas, %4$d con error.', 'wp-gemini-watermark-remover'),
            esc_html($label), $ok, $skipped, $failed
        );

        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($msg));
    }

    public function filter_js_attachment($response, $attachment) {
        if (empty($attachment->ID)) return $response;
        $v = (int) get_post_meta($attachment->ID, '_dgz_version', true);
        if ($v <= 0) return $response;

        if (!empty($response['url'])) {
            $response['url'] = add_query_arg('dgz', $v, $response['url']);
        }
        if (!empty($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as $k => $size) {
                if (!empty($size['url'])) {
                    $response['sizes'][$k]['url'] = add_query_arg('dgz', $v, $size['url']);
                }
            }
        }
        return $response;
    }
}
