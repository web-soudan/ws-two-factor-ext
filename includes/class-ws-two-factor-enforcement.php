<?php
/**
 * Enforcement class for WS Two Factor Extension.
 *
 * @package WS_Two_Factor_Ext
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 新規ユーザー作成時に 2FA プロバイダーを自動設定する Enforcement クラス。
 *
 * ルールは wp_options に保存し、user_register フックで自動適用します。
 */
class WS_Two_Factor_Enforcement {

	/** @var string オプションキー */
	private const OPTION_KEY = 'ws_2fa_enforcement_rule';

	/** @var WS_Two_Factor_Enforcement|null シングルトンインスタンス */
	private static ?WS_Two_Factor_Enforcement $instance = null;

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
	 * コンストラクタ。フックを登録します。
	 */
	private function __construct() {
		add_action( 'user_register', array( $this, 'on_user_register' ), 20 );
	}

	// -----------------------------------------------------------------------
	// フック
	// -----------------------------------------------------------------------

	/**
	 * 新規ユーザー作成時に強制ルールを適用します。
	 *
	 * @param int $user_id 作成されたユーザー ID。
	 */
	public function on_user_register( int $user_id ): void {
		$rule = $this->get_rule();

		if ( empty( $rule ) || empty( $rule['providers'] ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		// ロールフィルター
		if ( ! empty( $rule['roles'] ) && ! array_intersect( $user->roles, $rule['roles'] ) ) {
			return;
		}

		$this->apply_rule_to_user( $user, $rule );
	}

	// -----------------------------------------------------------------------
	// ルール CRUD
	// -----------------------------------------------------------------------

	/**
	 * 現在の強制ルールを取得します。
	 *
	 * @return array{providers: string[], primary: string, roles: string[]}|array{}
	 */
	public function get_rule(): array {
		$rule = get_option( self::OPTION_KEY, array() );
		return is_array( $rule ) ? $rule : array();
	}

	/**
	 * 強制ルールを保存します。
	 *
	 * @param array{providers: string[], primary?: string, roles?: string[]} $rule
	 */
	public function save_rule( array $rule ): void {
		// 登録済みプロバイダーのみ許可（未登録クラス名の混入を防ぐ）
		$registered_providers = array_keys( Two_Factor_Core::get_providers() );

		$raw_providers = array_map( 'sanitize_text_field', (array) ( $rule['providers'] ?? array() ) );
		$providers     = array_values( array_intersect( array_filter( $raw_providers ), $registered_providers ) );

		$raw_primary = sanitize_text_field( $rule['primary'] ?? '' );
		$primary     = in_array( $raw_primary, $registered_providers, true ) ? $raw_primary : '';

		$sanitized = array(
			'providers' => $providers,
			'primary'   => $primary,
			'roles'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $rule['roles'] ?? array() ) ) ) ),
		);
		update_option( self::OPTION_KEY, $sanitized, false );
	}

	/**
	 * 強制ルールを削除します。
	 */
	public function delete_rule(): void {
		delete_option( self::OPTION_KEY );
	}

	// -----------------------------------------------------------------------
	// 適用ロジック
	// -----------------------------------------------------------------------

	/**
	 * 指定ルールをユーザーに適用します。
	 *
	 * @param WP_User $user 適用対象ユーザー。
	 * @param array   $rule 適用するルール。
	 */
	public function apply_rule_to_user( WP_User $user, array $rule ): void {
		$providers = $rule['providers'] ?? array();
		$primary   = $rule['primary'] ?? '';

		if ( empty( $providers ) ) {
			return;
		}

		// 既存の有効プロバイダーとマージ（重複排除）
		$current  = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		$current  = is_array( $current ) ? $current : array();
		$merged   = array_values( array_unique( array_merge( $current, $providers ) ) );

		update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, $merged );

		// Primary プロバイダーの設定（まだ設定がない場合、または primary が指定されている場合）
		if ( $primary ) {
			$current_primary = get_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, true );
			if ( empty( $current_primary ) ) {
				update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, $primary );
			}
		}
	}
}
