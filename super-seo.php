<?php
/**
 * Plugin Name:       Super SEO 智能优化
 * Plugin URI:        https://example.com/super-seo
 * Description:       面向小白用户的中文 SEO 与 PageSpeed 优化插件，支持 DeepSeek/OpenAI 兼容大模型生成标题、描述和关键词。
 * Version:           1.0.0
 * Author:            Super SEO
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       super-seo
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package SuperSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SUPER_SEO_VERSION', '1.0.0' );
define( 'SUPER_SEO_FILE', __FILE__ );
define( 'SUPER_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'SUPER_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'SUPER_SEO_OPTION', 'super_seo_settings' );

require_once SUPER_SEO_PATH . 'includes/class-super-seo-helpers.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-ai.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-audit.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-automation.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-admin.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-meta.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-performance.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo-sitemap.php';
require_once SUPER_SEO_PATH . 'includes/class-super-seo.php';

/**
 * Returns the main plugin instance.
 *
 * @return Super_SEO
 */
function super_seo() {
	return Super_SEO::instance();
}

register_activation_hook( __FILE__, array( 'Super_SEO', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Super_SEO', 'deactivate' ) );

super_seo();
