<?php
if (!defined('ABSPATH')) {
    exit;
}

class DGZ_Settings {

    const OPTION = 'dgz_settings';
    const GROUP  = 'dgz_settings_group';
    const PAGE   = 'dgz-settings';

    public static function defaults() {
        return [
            'engine'       => 'algorithm',
            'gemini_key'   => '',
            'gemini_model' => 'gemini-2.5-flash-image-preview',
            'openai_key'   => '',
            'openai_model' => 'gpt-image-1',
        ];
    }

    public static function get($key = null) {
        $opts = wp_parse_args(get_option(self::OPTION, []), self::defaults());
        if ($key === null) return $opts;
        return $opts[$key] ?? null;
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_menu() {
        add_options_page(
            __('De-Geminizer WP', 'wp-gemini-watermark-remover'),
            __('De-Geminizer WP', 'wp-gemini-watermark-remover'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting(self::GROUP, self::OPTION, [
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    public static function sanitize($input) {
        $out = self::defaults();
        if (!is_array($input)) return $out;

        $engine = isset($input['engine']) ? sanitize_key($input['engine']) : 'algorithm';
        $out['engine'] = in_array($engine, ['algorithm', 'gemini', 'openai'], true) ? $engine : 'algorithm';

        $out['gemini_key']   = isset($input['gemini_key'])   ? trim((string) $input['gemini_key'])   : '';
        $out['openai_key']   = isset($input['openai_key'])   ? trim((string) $input['openai_key'])   : '';
        $out['gemini_model'] = isset($input['gemini_model']) ? sanitize_text_field($input['gemini_model']) : 'gemini-2.5-flash-image-preview';
        $out['openai_model'] = isset($input['openai_model']) ? sanitize_text_field($input['openai_model']) : 'gpt-image-1';
        return $out;
    }

    /**
     * Devuelve el motor efectivo: si el usuario eligió un proveedor IA pero
     * no tiene token configurado, cae al algoritmo local automáticamente.
     */
    public static function effective_engine() {
        $opts = self::get();
        if ($opts['engine'] === 'gemini' && !empty($opts['gemini_key'])) return 'gemini';
        if ($opts['engine'] === 'openai' && !empty($opts['openai_key'])) return 'openai';
        return 'algorithm';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $opts = self::get();
        $effective = self::effective_engine();
        ?>
        <div class="wrap dgz-settings">
            <h1><?php esc_html_e('De-Geminizer WP — Configuración', 'wp-gemini-watermark-remover'); ?></h1>

            <p>
                <?php esc_html_e('Elige el motor de eliminación. Si seleccionas un proveedor de IA pero no configuras su token, se usará automáticamente el algoritmo local como fallback.', 'wp-gemini-watermark-remover'); ?>
            </p>

            <?php if ($opts['engine'] !== 'algorithm' && $effective === 'algorithm'): ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('Aviso:', 'wp-gemini-watermark-remover'); ?></strong>
                        <?php
                        printf(
                            /* translators: %s: nombre del proveedor */
                            esc_html__('Has elegido %s pero falta la API key. De momento se usa el algoritmo local.', 'wp-gemini-watermark-remover'),
                            '<code>' . esc_html(strtoupper($opts['engine'])) . '</code>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>

                <h2 class="title"><?php esc_html_e('Motor', 'wp-gemini-watermark-remover'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="dgz_engine"><?php esc_html_e('Motor de eliminación', 'wp-gemini-watermark-remover'); ?></label></th>
                            <td>
                                <select id="dgz_engine" name="dgz_settings[engine]">
                                    <option value="algorithm" <?php selected($opts['engine'], 'algorithm'); ?>><?php esc_html_e('Algoritmo local (PHP/GD) — sin coste, sin red', 'wp-gemini-watermark-remover'); ?></option>
                                    <option value="gemini" <?php selected($opts['engine'], 'gemini'); ?>><?php esc_html_e('Google Gemini API (Nano Banana)', 'wp-gemini-watermark-remover'); ?></option>
                                    <option value="openai" <?php selected($opts['engine'], 'openai'); ?>><?php esc_html_e('OpenAI Images API (gpt-image-1)', 'wp-gemini-watermark-remover'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Motor efectivo actual:', 'wp-gemini-watermark-remover'); ?>
                                    <code><?php echo esc_html($effective); ?></code>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="title">Google Gemini</h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="dgz_gemini_key"><?php esc_html_e('API key', 'wp-gemini-watermark-remover'); ?></label></th>
                            <td>
                                <input id="dgz_gemini_key" name="dgz_settings[gemini_key]" type="password" class="regular-text" value="<?php echo esc_attr($opts['gemini_key']); ?>" autocomplete="off" />
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: enlace */
                                        esc_html__('Obtén una en %s.', 'wp-gemini-watermark-remover'),
                                        '<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">aistudio.google.com/apikey</a>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dgz_gemini_model"><?php esc_html_e('Modelo', 'wp-gemini-watermark-remover'); ?></label></th>
                            <td>
                                <input id="dgz_gemini_model" name="dgz_settings[gemini_model]" type="text" class="regular-text" value="<?php echo esc_attr($opts['gemini_model']); ?>" />
                                <p class="description"><?php esc_html_e('Por defecto: gemini-2.5-flash-image-preview (modelo de edición de imagen).', 'wp-gemini-watermark-remover'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="title">OpenAI</h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="dgz_openai_key"><?php esc_html_e('API key', 'wp-gemini-watermark-remover'); ?></label></th>
                            <td>
                                <input id="dgz_openai_key" name="dgz_settings[openai_key]" type="password" class="regular-text" value="<?php echo esc_attr($opts['openai_key']); ?>" autocomplete="off" />
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: enlace */
                                        esc_html__('Obtén una en %s.', 'wp-gemini-watermark-remover'),
                                        '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dgz_openai_model"><?php esc_html_e('Modelo', 'wp-gemini-watermark-remover'); ?></label></th>
                            <td>
                                <input id="dgz_openai_model" name="dgz_settings[openai_model]" type="text" class="regular-text" value="<?php echo esc_attr($opts['openai_model']); ?>" />
                                <p class="description"><?php esc_html_e('Por defecto: gpt-image-1. También válido: dall-e-2.', 'wp-gemini-watermark-remover'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
