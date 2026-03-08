<?php
/**
 * Lock class for WS Two Factor Extension.
 *
 * 非管理者ユーザーが自分の 2FA を無効化できないようにロックする機能。
 *
 * @package WS_Two_Factor_Ext
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 非管理者による 2FA 無効化をブロックするクラス。
 *
 * ロックが有効な場合:
 *   - プロフィール画面からの 2FA プロバイダー削除を防止
 *   - REST API (DELETE) からの 2FA プロバイダー削除を防止
 *   - プロフィール画面にロック中である旨の通知を表示
 *
 * ロックの有効/無効は wp_options に保存し、WP-CLI で切り替え可能。
 */
class WS_Two_Factor_Lock {

	/** @var string ロック状態を保存するオプションキー */
	private const OPTION_KEY = 'ws_2fa_lock_enabled';

	/** @var WS_Two_Factor_Lock|null シングルトンインスタンス */
	private static ?WS_Two_Factor_Lock $instance = null;

	/**
	 * シングルトン取得。
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * コンストラクタ。フック・フィルターを登録します。
	 *
	 * ロックの有効/無効に関わらず常に登録し、各コールバック内で is_enabled() を確認します。
	 */
	private function __construct() {
		// プロフィール保存時（Two Factor の priority 10 より前に実行）
		add_action( 'personal_options_update', array( $this, 'prevent_disable_on_profile_save' ), 5 );

		// REST API 経由での削除
		add_filter( 'two_factor_rest_api_can_edit_user', array( $this, 'prevent_disable_via_rest' ), 10, 2 );

		// プロフィール画面への通知表示
		add_action( 'show_user_profile', array( $this, 'show_lock_notice' ) );
	}

	// -----------------------------------------------------------------------
	// ロック設定
	// -----------------------------------------------------------------------

	/**
	 * ロックが有効かどうかを返します。
	 */
	public function is_enabled(): bool {
		return (bool) get_option( self::OPTION_KEY, false );
	}

	/**
	 * ロックを有効化します。
	 */
	public function enable(): void {
		update_option( self::OPTION_KEY, true, false );
	}

	/**
	 * ロックを無効化します。
	 */
	public function disable(): void {
		delete_option( self::OPTION_KEY );
	}

	// -----------------------------------------------------------------------
	// フック・フィルター
	// -----------------------------------------------------------------------

	/**
	 * プロフィール保存時に非管理者が既存の 2FA プロバイダーを削除しようとするのを防ぎます。
	 *
	 * Two Factor の `user_two_factor_options_update` (priority 10) より前に実行し、
	 * $_POST データに既存プロバイダーを強制的にマージすることで削除を防止します。
	 *
	 * @param int $user_id 保存対象ユーザー ID。
	 */
	public function prevent_disable_on_profile_save( int $user_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// 管理者は制限なし
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// 既存の有効プロバイダーを取得
		$existing = get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( empty( $existing ) || ! is_array( $existing ) ) {
			return; // 2FA 未設定なら制限不要
		}

		// フォームから送信されたプロバイダーに既存分を強制マージ（削除不可・追加のみ許可）
		$submitted = isset( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] )
			? (array) $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ]
			: array();

		$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = array_values(
			array_unique( array_merge( $existing, $submitted ) )
		);

		// Primary プロバイダーも保持（空で送信された場合は既存値を維持）
		$existing_primary = get_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true );
		if ( $existing_primary && empty( $_POST[ Two_Factor_Core::PROVIDER_USER_META_KEY ] ) ) {
			$_POST[ Two_Factor_Core::PROVIDER_USER_META_KEY ] = $existing_primary;
		}
	}

	/**
	 * REST API 経由での 2FA プロバイダー削除を非管理者に対してブロックします。
	 *
	 * Two Factor の `two_factor_rest_api_can_edit_user` フィルターに乗り、
	 * DELETE メソッドかつ 2FA 設定済みユーザーへのリクエストを 403 で拒否します。
	 *
	 * @param bool|WP_Error $can     現在の許可状態。
	 * @param int           $user_id 操作対象ユーザー ID。
	 * @return bool|WP_Error
	 */
	public function prevent_disable_via_rest( $can, int $user_id ) {
		if ( ! $this->is_enabled() ) {
			return $can;
		}

		// 管理者は制限なし
		if ( current_user_can( 'manage_options' ) ) {
			return $can;
		}

		// DELETE リクエスト（プロバイダー削除）のみブロック
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' );
		if ( 'DELETE' !== $method ) {
			return $can;
		}

		// 2FA が設定済みの場合のみブロック（未設定なら設定変更を妨げない）
		$existing = get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( empty( $existing ) ) {
			return $can;
		}

		return new WP_Error(
			'two_factor_locked',
			__( '2FA の無効化は管理者のみが行えます。', 'ws-two-factor-ext' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * プロフィール画面でロック中である旨の通知を表示します。
	 *
	 * @param WP_User $user 表示対象ユーザー。
	 */
	public function show_lock_notice( WP_User $user ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// 管理者には通知不要
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// 2FA が設定済みのユーザーにのみ通知
		$existing = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( empty( $existing ) ) {
			return;
		}

		?>
		<div class="notice notice-warning inline" style="margin: 1em 0 0;">
			<p>
				<strong><?php esc_html_e( '2ファクター認証はロックされています。', 'ws-two-factor-ext' ); ?></strong>
				<?php esc_html_e( '現在の 2FA 設定を無効化・削除するには管理者へお問い合わせください。', 'ws-two-factor-ext' ); ?>
			</p>
		</div>
		<?php
	}
}
