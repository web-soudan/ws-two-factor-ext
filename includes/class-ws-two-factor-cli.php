<?php
/**
 * WP-CLI commands for WS Two Factor Extension.
 *
 * @package WS_Two_Factor_Ext
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Two Factor settings per-user via WP-CLI.
 *
 * ## EXAMPLES
 *
 *     # 全ユーザーの 2FA 設定を一覧表示
 *     wp 2fa-ex list
 *
 *     # 特定ユーザーの詳細を表示
 *     wp 2fa-ex status admin
 *
 *     # Email プロバイダーを有効化
 *     wp 2fa-ex enable admin --provider=email
 *
 *     # 2FA を無効化
 *     wp 2fa-ex disable admin
 *
 *     # 強制ルールを設定してから既存ユーザーに適用
 *     wp 2fa-ex set-enforce --provider=email --role=subscriber
 *     wp 2fa-ex apply-enforce --role=subscriber
 */
class WS_Two_Factor_CLI {

	/**
	 * プロバイダーの短縮名 → クラス名マッピング。
	 */
	private const PROVIDER_ALIASES = array(
		'email'    => 'Two_Factor_Email',
		'totp'     => 'Two_Factor_Totp',
		'backup'   => 'Two_Factor_Backup_Codes',
		'fido-u2f' => 'Two_Factor_FIDO_U2F',
	);

	// -----------------------------------------------------------------------
	// list
	// -----------------------------------------------------------------------

	/**
	 * 全ユーザーの 2FA 設定状況を一覧表示します。
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : 指定したロールのユーザーのみ表示します。
	 *
	 * [--enabled-only]
	 * : 2FA が有効なユーザーのみ表示します。
	 *
	 * [--fields=<fields>]
	 * : 表示するフィールドをカンマ区切りで指定します。
	 * デフォルト: ID,user_login,email,enabled_providers,primary_provider
	 *
	 * [--format=<format>]
	 * : 出力形式 (table, csv, json, yaml)。デフォルト: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex list
	 *     wp 2fa-ex list --role=subscriber
	 *     wp 2fa-ex list --enabled-only --format=csv
	 *
	 * @subcommand list
	 */
	public function list_users( array $args, array $assoc_args ): void {
		$query_args = array(
			'number'  => -1,
			'fields'  => 'all',
			'orderby' => 'ID',
		);

		if ( ! empty( $assoc_args['role'] ) ) {
			$query_args['role'] = sanitize_text_field( $assoc_args['role'] );
		}

		$users = get_users( $query_args );

		$rows            = array();
		$enabled_only    = isset( $assoc_args['enabled-only'] );
		$default_fields  = 'ID,user_login,email,enabled_providers,primary_provider';
		$fields          = explode( ',', $assoc_args['fields'] ?? $default_fields );

		foreach ( $users as $user ) {
			$enabled   = $this->get_enabled_providers( $user );
			$primary   = $this->get_primary_provider( $user );

			if ( $enabled_only && empty( $enabled ) ) {
				continue;
			}

			$rows[] = array(
				'ID'                => $user->ID,
				'user_login'        => $user->user_login,
				'email'             => $user->user_email,
				'enabled_providers' => implode( ', ', array_map( array( $this, 'class_to_alias' ), $enabled ) ),
				'primary_provider'  => $primary ? $this->class_to_alias( $primary ) : '(none)',
				'roles'             => implode( ', ', $user->roles ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::line( '該当するユーザーが見つかりませんでした。' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	// -----------------------------------------------------------------------
	// status
	// -----------------------------------------------------------------------

	/**
	 * 特定ユーザーの 2FA 詳細ステータスを表示します。
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : ユーザー ID、ログイン名、またはメールアドレス。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex status admin
	 *     wp 2fa-ex status 1
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$user = $this->get_user_or_error( $args[0] );

		$enabled = $this->get_enabled_providers( $user );
		$primary = $this->get_primary_provider( $user );

		$all_providers = Two_Factor_Core::get_providers();

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '  ユーザー  : %s (ID: %d)', $user->user_login, $user->ID ) );
		WP_CLI::line( sprintf( '  メール    : %s', $user->user_email ) );
		WP_CLI::line( sprintf( '  ロール    : %s', implode( ', ', $user->roles ) ) );
		WP_CLI::line( '' );
		WP_CLI::line( '  ■ 2FA プロバイダー設定状況' );
		WP_CLI::line( '' );

		foreach ( $all_providers as $class => $provider ) {
			$alias       = $this->class_to_alias( $class );
			$is_enabled  = in_array( $class, $enabled, true );
			$is_primary  = $class === $primary;
			$is_avail    = $provider->is_available_for_user( $user );

			$status_icon  = $is_enabled ? WP_CLI::colorize( '%G✔%n' ) : WP_CLI::colorize( '%r✘%n' );
			$primary_mark = $is_primary ? WP_CLI::colorize( ' %Y[primary]%n' ) : '';
			$avail_mark   = ! $is_avail && $is_enabled ? WP_CLI::colorize( ' %r[未設定]%n' ) : '';

			WP_CLI::line(
				sprintf(
					'    %s  %-12s  %-30s%s%s',
					$status_icon,
					$alias,
					$class,
					$primary_mark,
					$avail_mark
				)
			);
		}

		WP_CLI::line( '' );

		// 強制ルールの確認
		$enforcement = WS_Two_Factor_Enforcement::get_instance();
		$rule        = $enforcement->get_rule();

		if ( ! empty( $rule ) && ! empty( $rule['providers'] ) ) {
			$applies = empty( $rule['roles'] ) || array_intersect( $user->roles, $rule['roles'] );
			if ( $applies ) {
				WP_CLI::line( '  ■ 強制ルール (このユーザーに適用対象)' );
				WP_CLI::line( sprintf( '    プロバイダー: %s', implode( ', ', array_map( array( $this, 'class_to_alias' ), $rule['providers'] ) ) ) );
				if ( ! empty( $rule['primary'] ) ) {
					WP_CLI::line( sprintf( '    Primary:      %s', $this->class_to_alias( $rule['primary'] ) ) );
				}
				WP_CLI::line( '' );
			}
		}
	}

	// -----------------------------------------------------------------------
	// enable
	// -----------------------------------------------------------------------

	/**
	 * ユーザーの 2FA プロバイダーを有効化します。
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : ユーザー ID、ログイン名、またはメールアドレス。
	 *
	 * --provider=<provider>
	 * : 有効化するプロバイダー (email, totp, backup, fido-u2f)。
	 *
	 * [--set-primary]
	 * : 有効化したプロバイダーを Primary にも設定します。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex enable admin --provider=email
	 *     wp 2fa-ex enable admin --provider=totp --set-primary
	 *
	 * @subcommand enable
	 */
	public function enable( array $args, array $assoc_args ): void {
		$user  = $this->get_user_or_error( $args[0] );
		$class = $this->resolve_provider_or_error( $assoc_args['provider'] ?? '' );

		$enabled = $this->get_enabled_providers( $user );

		if ( in_array( $class, $enabled, true ) ) {
			WP_CLI::warning( sprintf( '%s はすでに有効です。', $this->class_to_alias( $class ) ) );
		} else {
			$enabled[] = $class;
			update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, $enabled );
			WP_CLI::success( sprintf( '%s (ID: %d) の %s を有効化しました。', $user->user_login, $user->ID, $this->class_to_alias( $class ) ) );
		}

		if ( isset( $assoc_args['set-primary'] ) ) {
			update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, $class );
			WP_CLI::success( sprintf( '%s を Primary プロバイダーに設定しました。', $this->class_to_alias( $class ) ) );
		}
	}

	// -----------------------------------------------------------------------
	// disable
	// -----------------------------------------------------------------------

	/**
	 * ユーザーの 2FA プロバイダーを無効化します。
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : ユーザー ID、ログイン名、またはメールアドレス。
	 *
	 * [--provider=<provider>]
	 * : 無効化するプロバイダー (email, totp, backup, fido-u2f)。
	 * 省略した場合は 2FA を全て無効化します。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex disable admin --provider=email
	 *     wp 2fa-ex disable admin
	 *
	 * @subcommand disable
	 */
	public function disable( array $args, array $assoc_args ): void {
		$user = $this->get_user_or_error( $args[0] );

		if ( empty( $assoc_args['provider'] ) ) {
			// 全プロバイダー無効化
			delete_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY );
			delete_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY );
			WP_CLI::success( sprintf( '%s (ID: %d) の 2FA を全て無効化しました。', $user->user_login, $user->ID ) );
			return;
		}

		$class   = $this->resolve_provider_or_error( $assoc_args['provider'] );
		$enabled = $this->get_enabled_providers( $user );

		if ( ! in_array( $class, $enabled, true ) ) {
			WP_CLI::warning( sprintf( '%s は有効化されていません。', $this->class_to_alias( $class ) ) );
			return;
		}

		$enabled = array_values( array_diff( $enabled, array( $class ) ) );
		update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, $enabled );

		// Primary が削除対象なら Primary もクリア
		$primary = $this->get_primary_provider( $user );
		if ( $primary === $class ) {
			delete_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY );
			WP_CLI::line( '  Primary プロバイダーもクリアしました。' );
		}

		WP_CLI::success( sprintf( '%s (ID: %d) の %s を無効化しました。', $user->user_login, $user->ID, $this->class_to_alias( $class ) ) );
	}

	// -----------------------------------------------------------------------
	// set-primary
	// -----------------------------------------------------------------------

	/**
	 * ユーザーの Primary 2FA プロバイダーを設定します。
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : ユーザー ID、ログイン名、またはメールアドレス。
	 *
	 * --provider=<provider>
	 * : Primary に設定するプロバイダー (email, totp, backup, fido-u2f)。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex set-primary admin --provider=totp
	 *
	 * @subcommand set-primary
	 */
	public function set_primary( array $args, array $assoc_args ): void {
		$user  = $this->get_user_or_error( $args[0] );
		$class = $this->resolve_provider_or_error( $assoc_args['provider'] ?? '' );

		$enabled = $this->get_enabled_providers( $user );
		if ( ! in_array( $class, $enabled, true ) ) {
			WP_CLI::error( sprintf( '%s は有効化されていません。先に enable コマンドで有効化してください。', $this->class_to_alias( $class ) ) );
		}

		update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, $class );
		WP_CLI::success( sprintf( '%s (ID: %d) の Primary プロバイダーを %s に設定しました。', $user->user_login, $user->ID, $this->class_to_alias( $class ) ) );
	}

	// -----------------------------------------------------------------------
	// set-enforce
	// -----------------------------------------------------------------------

	/**
	 * 新規ユーザー作成時に自動適用する 2FA 強制ルールを設定します。
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<providers>]
	 * : 強制するプロバイダーをカンマ区切りで指定 (例: email,backup)。
	 *
	 * [--primary=<provider>]
	 * : Primary プロバイダーを指定 (例: email)。
	 *
	 * [--role=<roles>]
	 * : 適用対象のロールをカンマ区切りで指定。省略時は全ロールに適用。
	 *
	 * [--all]
	 * : ルールを保存した後、既存の全ユーザーにも即時適用します。
	 *
	 * [--overwrite]
	 * : --all と組み合わせて使用。すでに 2FA が設定済みのユーザーにも上書き適用します。
	 *
	 * [--dry-run]
	 * : --all と組み合わせて使用。実際には変更せず、適用予定の内容を表示します。
	 *
	 * [--disable]
	 * : 強制ルールを無効化します。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex set-enforce --provider=email --role=subscriber
	 *     wp 2fa-ex set-enforce --provider=email,backup --primary=email
	 *     wp 2fa-ex set-enforce --provider=email --all
	 *     wp 2fa-ex set-enforce --provider=email --all --dry-run
	 *     wp 2fa-ex set-enforce --disable
	 *
	 * @subcommand set-enforce
	 */
	public function set_enforce( array $args, array $assoc_args ): void {
		$enforcement = WS_Two_Factor_Enforcement::get_instance();

		if ( isset( $assoc_args['disable'] ) ) {
			$enforcement->delete_rule();
			WP_CLI::success( '強制ルールを削除しました。' );
			return;
		}

		if ( empty( $assoc_args['provider'] ) ) {
			WP_CLI::error( '--provider オプションを指定してください。' );
		}

		$provider_aliases = explode( ',', $assoc_args['provider'] );
		$classes          = array();
		foreach ( $provider_aliases as $alias ) {
			$classes[] = $this->resolve_provider_or_error( trim( $alias ) );
		}

		$primary = '';
		if ( ! empty( $assoc_args['primary'] ) ) {
			$primary = $this->resolve_provider_or_error( trim( $assoc_args['primary'] ) );
			if ( ! in_array( $primary, $classes, true ) ) {
				WP_CLI::error( '--primary に指定したプロバイダーは --provider にも含める必要があります。' );
			}
		}

		$roles = array();
		if ( ! empty( $assoc_args['role'] ) ) {
			$roles = array_map( 'trim', explode( ',', $assoc_args['role'] ) );
		}

		$rule = array(
			'providers' => $classes,
			'primary'   => $primary,
			'roles'     => $roles,
		);

		$dry_run = isset( $assoc_args['dry-run'] );

		if ( ! $dry_run ) {
			$enforcement->save_rule( $rule );
			WP_CLI::success( '強制ルールを保存しました。' );
		} else {
			WP_CLI::line( WP_CLI::colorize( '%Y[dry-run] ルールは保存しません。%n' ) );
		}

		WP_CLI::line( sprintf( '  プロバイダー: %s', implode( ', ', array_map( array( $this, 'class_to_alias' ), $classes ) ) ) );
		if ( $primary ) {
			WP_CLI::line( sprintf( '  Primary:      %s', $this->class_to_alias( $primary ) ) );
		}
		WP_CLI::line( sprintf( '  対象ロール:   %s', empty( $roles ) ? '(全ロール)' : implode( ', ', $roles ) ) );

		// --all: 既存ユーザーへの即時適用
		if ( isset( $assoc_args['all'] ) ) {
			WP_CLI::line( '' );
			$this->run_apply_enforce( $rule, $dry_run, isset( $assoc_args['overwrite'] ) );
		} else {
			WP_CLI::line( '  ※ 新規ユーザー作成時に自動適用されます。' );
			WP_CLI::line( '  ※ 既存ユーザーへ適用するには: wp 2fa-ex apply-enforce' );
		}
	}

	// -----------------------------------------------------------------------
	// apply-enforce
	// -----------------------------------------------------------------------

	/**
	 * 強制ルールを既存ユーザーに適用します。
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : 特定のユーザーにのみ適用 (ID、ログイン名、メールアドレス)。
	 *
	 * [--role=<role>]
	 * : 指定したロールのユーザーにのみ適用。
	 *
	 * [--overwrite]
	 * : すでに 2FA が設定済みのユーザーにも上書き適用します。
	 *
	 * [--dry-run]
	 * : 実際には変更せず、適用予定の内容を表示します。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex apply-enforce
	 *     wp 2fa-ex apply-enforce --role=subscriber
	 *     wp 2fa-ex apply-enforce --user=john --dry-run
	 *
	 * @subcommand apply-enforce
	 */
	public function apply_enforce( array $args, array $assoc_args ): void {
		$enforcement = WS_Two_Factor_Enforcement::get_instance();
		$rule        = $enforcement->get_rule();

		if ( empty( $rule ) || empty( $rule['providers'] ) ) {
			WP_CLI::error( '強制ルールが設定されていません。先に wp 2fa-ex set-enforce で設定してください。' );
		}

		// --user / --role でユーザーを絞り込む
		if ( ! empty( $assoc_args['user'] ) ) {
			$users = array( $this->get_user_or_error( $assoc_args['user'] ) );
		} else {
			$query_args = array(
				'number'  => -1,
				'fields'  => 'all',
				'orderby' => 'ID',
			);
			$role = $assoc_args['role'] ?? '';
			if ( $role ) {
				$query_args['role'] = sanitize_text_field( $role );
			} elseif ( ! empty( $rule['roles'] ) ) {
				$query_args['role__in'] = $rule['roles'];
			}
			$users = get_users( $query_args );
		}

		$this->run_apply_enforce( $rule, isset( $assoc_args['dry-run'] ), isset( $assoc_args['overwrite'] ), $users );
	}

	// -----------------------------------------------------------------------
	// show-enforce
	// -----------------------------------------------------------------------

	/**
	 * 現在の強制ルールを表示します。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex show-enforce
	 *
	 * @subcommand show-enforce
	 */
	public function show_enforce( array $args, array $assoc_args ): void {
		$enforcement = WS_Two_Factor_Enforcement::get_instance();
		$rule        = $enforcement->get_rule();

		if ( empty( $rule ) || empty( $rule['providers'] ) ) {
			WP_CLI::line( '強制ルールは設定されていません。' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '  ■ 現在の強制ルール' );
		WP_CLI::line( sprintf( '  プロバイダー: %s', implode( ', ', array_map( array( $this, 'class_to_alias' ), $rule['providers'] ) ) ) );
		if ( ! empty( $rule['primary'] ) ) {
			WP_CLI::line( sprintf( '  Primary:      %s', $this->class_to_alias( $rule['primary'] ) ) );
		}
		WP_CLI::line( sprintf( '  対象ロール:   %s', empty( $rule['roles'] ) ? '(全ロール)' : implode( ', ', $rule['roles'] ) ) );
		WP_CLI::line( '' );
	}

	// -----------------------------------------------------------------------
	// lock-enable / lock-disable / lock-status
	// -----------------------------------------------------------------------

	/**
	 * 非管理者による 2FA 無効化をロックします。
	 *
	 * 有効化すると、管理者以外のユーザーは自分の 2FA プロバイダーを
	 * プロフィール画面や REST API から削除できなくなります。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex lock-enable
	 *
	 * @subcommand lock-enable
	 */
	public function lock_enable( array $args, array $assoc_args ): void {
		WS_Two_Factor_Lock::get_instance()->enable();
		WP_CLI::success( '2FA ロックを有効化しました。非管理者は 2FA を無効化できなくなります。' );
	}

	/**
	 * 2FA ロックを解除します。
	 *
	 * 解除すると、ユーザーは自分の 2FA 設定を自由に変更できます。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex lock-disable
	 *
	 * @subcommand lock-disable
	 */
	public function lock_disable( array $args, array $assoc_args ): void {
		WS_Two_Factor_Lock::get_instance()->disable();
		WP_CLI::success( '2FA ロックを無効化しました。' );
	}

	/**
	 * 2FA ロック機能の現在の状態を表示します。
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex lock-status
	 *
	 * @subcommand lock-status
	 */
	public function lock_status( array $args, array $assoc_args ): void {
		$enabled = WS_Two_Factor_Lock::get_instance()->is_enabled();
		if ( $enabled ) {
			WP_CLI::line( WP_CLI::colorize( '  2FA ロック: %G有効%n (非管理者は 2FA を無効化できません)' ) );
		} else {
			WP_CLI::line( WP_CLI::colorize( '  2FA ロック: %r無効%n' ) );
		}
	}

	// -----------------------------------------------------------------------
	// ヘルパーメソッド
	// -----------------------------------------------------------------------

	/**
	 * 指定ルールをユーザーリストに適用する共通処理。
	 *
	 * @param array     $rule      適用するルール。
	 * @param bool      $dry_run   true の場合は変更しない。
	 * @param bool      $overwrite true の場合はすでに 2FA 設定済みのユーザーにも上書き。
	 * @param WP_User[] $users     対象ユーザーリスト。省略時は全ユーザー。
	 */
	private function run_apply_enforce( array $rule, bool $dry_run, bool $overwrite, array $users = array() ): void {
		$enforcement = WS_Two_Factor_Enforcement::get_instance();

		if ( empty( $users ) ) {
			$query_args = array(
				'number'  => -1,
				'fields'  => 'all',
				'orderby' => 'ID',
			);
			if ( ! empty( $rule['roles'] ) ) {
				$query_args['role__in'] = $rule['roles'];
			}
			$users = get_users( $query_args );
		}

		if ( $dry_run ) {
			WP_CLI::line( WP_CLI::colorize( '%Y[dry-run] 実際には変更しません。%n' ) );
		}

		$applied = 0;
		$skipped = 0;

		foreach ( $users as $user ) {
			// ロールフィルター（ルール側のロール制限）
			if ( ! empty( $rule['roles'] ) && ! array_intersect( $user->roles, $rule['roles'] ) ) {
				continue;
			}

			$enabled = $this->get_enabled_providers( $user );

			if ( ! $overwrite && ! empty( $enabled ) ) {
				WP_CLI::line( sprintf( '  スキップ: %s (ID: %d) - すでに 2FA が設定されています。', $user->user_login, $user->ID ) );
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::line(
					sprintf(
						'  [dry-run] %s (ID: %d): %s を有効化%s',
						$user->user_login,
						$user->ID,
						implode( ', ', array_map( array( $this, 'class_to_alias' ), $rule['providers'] ) ),
						$rule['primary'] ? sprintf( ', Primary: %s', $this->class_to_alias( $rule['primary'] ) ) : ''
					)
				);
			} else {
				$enforcement->apply_rule_to_user( $user, $rule );
				WP_CLI::line( sprintf( '  適用: %s (ID: %d)', $user->user_login, $user->ID ) );
			}

			++$applied;
		}

		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::success( sprintf( '[dry-run] %d 件が適用対象、%d 件がスキップ対象です。', $applied, $skipped ) );
		} else {
			WP_CLI::success( sprintf( '%d 件に適用しました。%d 件はスキップしました。', $applied, $skipped ) );
		}
	}

	/**
	 * ユーザーを取得する。見つからない場合は WP_CLI::error() で終了。
	 */
	private function get_user_or_error( string $identifier ): WP_User {
		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		} elseif ( strpos( $identifier, '@' ) !== false ) {
			$user = get_user_by( 'email', $identifier );
		} else {
			$user = get_user_by( 'login', $identifier );
		}

		if ( ! $user ) {
			WP_CLI::error( sprintf( 'ユーザーが見つかりません: %s', $identifier ) );
		}

		return $user;
	}

	/**
	 * プロバイダーエイリアスをクラス名に変換する。不明な場合は WP_CLI::error()。
	 */
	private function resolve_provider_or_error( string $alias ): string {
		if ( empty( $alias ) ) {
			$available = implode( ', ', array_keys( self::PROVIDER_ALIASES ) );
			WP_CLI::error( sprintf( '--provider を指定してください。使用可能: %s', $available ) );
		}

		// エイリアス一致
		if ( isset( self::PROVIDER_ALIASES[ $alias ] ) ) {
			return self::PROVIDER_ALIASES[ $alias ];
		}

		// 完全なクラス名として渡された場合
		$providers = Two_Factor_Core::get_providers();
		if ( isset( $providers[ $alias ] ) ) {
			return $alias;
		}

		WP_CLI::error(
			sprintf(
				'不明なプロバイダー: "%s"。使用可能: %s',
				$alias,
				implode( ', ', array_keys( self::PROVIDER_ALIASES ) )
			)
		);
	}

	/**
	 * クラス名をエイリアスに変換する（表示用）。
	 */
	private function class_to_alias( string $class ): string {
		$flipped = array_flip( self::PROVIDER_ALIASES );
		return $flipped[ $class ] ?? $class;
	}

	/**
	 * ユーザーの有効なプロバイダーリストを取得する。
	 *
	 * @return string[]
	 */
	private function get_enabled_providers( WP_User $user ): array {
		$meta = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * ユーザーの Primary プロバイダーを取得する。
	 */
	private function get_primary_provider( WP_User $user ): string {
		return (string) get_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, true );
	}
}
