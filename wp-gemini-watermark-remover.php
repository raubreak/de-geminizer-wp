<?php
/**
 * Plugin Name: De-Geminizer WP
 * Plugin URI:  https://raulsantos.dev/
 * Description: Añade un botón "De-Geminizer" en la mediateca para eliminar el watermark de Gemini (el destello ✦) de las imágenes generadas por IA.
 * Version:     1.1.0
 * Author:      Raul Santos
 * License:     GPL-2.0-or-later
 * Text Domain: wp-gemini-watermark-remover
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DGZ_VERSION', '1.1.0');
define('DGZ_PLUGIN_FILE', __FILE__);
define('DGZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DGZ_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DGZ_PLUGIN_DIR . 'includes/class-watermark-remover.php';
require_once DGZ_PLUGIN_DIR . 'includes/class-ai-provider.php';
require_once DGZ_PLUGIN_DIR . 'includes/class-settings.php';
require_once DGZ_PLUGIN_DIR . 'includes/class-plugin.php';

add_action('plugins_loaded', function () {
    DGZ_Settings::init();
    DGZ_Plugin::instance();
});
