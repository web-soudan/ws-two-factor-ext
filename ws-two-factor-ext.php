<?php
/**
 * Plugin Name: WS Two Factor Extension
 * Description: WP-CLI commands and enforcement features extending the Two Factor plugin.
 * Version:     1.0.0
 * Author:      株式会社Webの相談所
 * Author URI:  https://web-soudan.co.jp/
 * License:     GPL-2.0-or-later
 * Text Domain: ws-two-factor-ext
 *
 * Requires Plugins: two-factor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WS_2FA_EXT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WS_2FA_EXT_VERSION', '1.0.0' );

/**
 * Two Factor プラグインが有効かどうかチェック。
 */
function ws_2fa_ext_check_dependency(): bool {
	return class_exists( 'Two_Factor_Core' );
}

/**
 * 依存プラグインがない場合の管理画面通知。
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! ws_2fa_ext_check_dependency() ) {
			echo '<div class="notice notice-error"><p><strong>WS Two Factor Extension:</strong> '
				. esc_html__( 'Two Factor プラグインが有効化されていません。', 'ws-two-factor-ext' )
				. '</p></div>';
		}
	}
);

/**
 * プラグイン本体の初期化。
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! ws_2fa_ext_check_dependency() ) {
			return;
		}

		// Enforcement クラス（常時ロード）
		require_once WS_2FA_EXT_DIR . 'includes/class-ws-two-factor-enforcement.php';
		WS_Two_Factor_Enforcement::get_instance();

		// Lock クラス（常時ロード）
		require_once WS_2FA_EXT_DIR . 'includes/class-ws-two-factor-lock.php';
		WS_Two_Factor_Lock::get_instance();

		// WP-CLI コマンド
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once WS_2FA_EXT_DIR . 'includes/class-ws-two-factor-cli.php';
			WP_CLI::add_command( '2fa-ex', 'WS_Two_Factor_CLI' );
		}
	}
);
