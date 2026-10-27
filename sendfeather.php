<?php
/**
 * Plugin Name:       Sendfeather SMTP
 * Plugin URI:        https://sendfeather.com/docs/smtp
 * Description:       Route WordPress email through Sendfeather — over the HTTP API with a single key, or standard SMTP credentials. Works with any plugin that uses wp_mail(), including WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sendfeather
 * Author URI:        https://sendfeather.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sendfeather
 * Domain Path:       /languages
 *
 * @package Sendfeather\SMTP
 */

namespace Sendfeather\SMTP;

defined( 'ABSPATH' ) || exit;

define( 'SENDFEATHER_VERSION', '1.0.0' );
define( 'SENDFEATHER_PLUGIN_FILE', __FILE__ );
define( 'SENDFEATHER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SENDFEATHER_PLUGIN_DIR . 'includes/class-api-client.php';
require_once SENDFEATHER_PLUGIN_DIR . 'includes/class-settings.php';
require_once SENDFEATHER_PLUGIN_DIR . 'includes/class-mailer.php';
require_once SENDFEATHER_PLUGIN_DIR . 'includes/class-plugin.php';

Plugin::boot();
