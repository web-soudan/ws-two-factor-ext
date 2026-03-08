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
 *     # List 2FA status of all users
 *     wp 2fa-ex list
 *
 *     # Show detailed status of a user
 *     wp 2fa-ex status admin
 *
 *     # Enable Email provider
 *     wp 2fa-ex enable admin --provider=email
 *
 *     # Disable 2FA
 *     wp 2fa-ex disable admin
 *
 *     # Save enforcement rule and apply to existing users
 *     wp 2fa-ex set-enforce --provider=email --role=subscriber
 *     wp 2fa-ex apply-enforce --role=subscriber
 */
class WS_Two_Factor_CLI {

	/**
	 * Provider alias → class name mapping.
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
	 * List 2FA configuration status for all users.
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : Filter by role.
	 *
	 * [--enabled-only]
	 * : Show only users with 2FA enabled.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to display.
	 * Default: ID,user_login,email,enabled_providers,primary_provider
	 *
	 * [--format=<format>]
	 * : Output format (table, csv, json, yaml). Default: table
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

		$rows           = array();
		$enabled_only   = isset( $assoc_args['enabled-only'] );
		$default_fields = 'ID,user_login,email,enabled_providers,primary_provider';
		$fields         = explode( ',', $assoc_args['fields'] ?? $default_fields );

		foreach ( $users as $user ) {
			$enabled = $this->get_enabled_providers( $user );
			$primary = $this->get_primary_provider( $user );

			if ( $enabled_only && empty( $enabled ) ) {
				continue;
			}

			$rows[] = array(
				'ID'                => $user->ID,
				'user_login'        => $user->user_login,
				'email'             => $user->user_email,
				'enabled_providers' => implode( ', ', array_map( array( $this, 'class_to_alias' ), $enabled ) ),
				/* translators: shown when no primary provider is set */
				'primary_provider'  => $primary ? $this->class_to_alias( $primary ) : __( '(none)', 'ws-two-factor-ext' ),
				'roles'             => implode( ', ', $user->roles ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::line( __( 'No matching users found.', 'ws-two-factor-ext' ) );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	// -----------------------------------------------------------------------
	// status
	// -----------------------------------------------------------------------

	/**
	 * Show detailed 2FA status for a specific user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address.
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
		/* translators: 1: user login, 2: user ID */
		WP_CLI::line( sprintf( __( '  User     : %1$s (ID: %2$d)', 'ws-two-factor-ext' ), $user->user_login, $user->ID ) );
		/* translators: %s: email address */
		WP_CLI::line( sprintf( __( '  Email    : %s', 'ws-two-factor-ext' ), $user->user_email ) );
		/* translators: %s: comma-separated list of roles */
		WP_CLI::line( sprintf( __( '  Roles    : %s', 'ws-two-factor-ext' ), implode( ', ', $user->roles ) ) );
		WP_CLI::line( '' );
		WP_CLI::line( __( '  2FA Provider Status', 'ws-two-factor-ext' ) );
		WP_CLI::line( '' );

		foreach ( $all_providers as $class => $provider ) {
			$alias      = $this->class_to_alias( $class );
			$is_enabled = in_array( $class, $enabled, true );
			$is_primary = $class === $primary;
			$is_avail   = $provider->is_available_for_user( $user );

			$status_icon  = $is_enabled ? WP_CLI::colorize( '%G✔%n' ) : WP_CLI::colorize( '%r✘%n' );
			$primary_mark = $is_primary ? WP_CLI::colorize( ' %Y[' . __( 'primary', 'ws-two-factor-ext' ) . ']%n' ) : '';
			/* translators: label shown when provider is enabled but not yet configured */
			$avail_mark = ! $is_avail && $is_enabled ? WP_CLI::colorize( ' %r[' . __( 'not configured', 'ws-two-factor-ext' ) . ']%n' ) : '';

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

		// Show enforcement rule if applicable.
		$enforcement = WS_Two_Factor_Enforcement::get_instance();
		$rule        = $enforcement->get_rule();

		if ( ! empty( $rule ) && ! empty( $rule['providers'] ) ) {
			$applies = empty( $rule['roles'] ) || array_intersect( $user->roles, $rule['roles'] );
			if ( $applies ) {
				WP_CLI::line( __( '  Enforcement rule (applies to this user)', 'ws-two-factor-ext' ) );
				/* translators: %s: comma-separated list of provider aliases */
				WP_CLI::line( sprintf( __( '    Providers: %s', 'ws-two-factor-ext' ), implode( ', ', array_map( array( $this, 'class_to_alias' ), $rule['providers'] ) ) ) );
				if ( ! empty( $rule['primary'] ) ) {
					/* translators: %s: provider alias */
					WP_CLI::line( sprintf( __( '    Primary:   %s', 'ws-two-factor-ext' ), $this->class_to_alias( $rule['primary'] ) ) );
				}
				WP_CLI::line( '' );
			}
		}
	}

	// -----------------------------------------------------------------------
	// enable
	// -----------------------------------------------------------------------

	/**
	 * Enable a 2FA provider for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address.
	 *
	 * --provider=<provider>
	 * : Provider to enable (email, totp, backup, fido-u2f).
	 *
	 * [--set-primary]
	 * : Also set the enabled provider as the primary provider.
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
			/* translators: %s: provider alias */
			WP_CLI::warning( sprintf( __( '%s is already enabled.', 'ws-two-factor-ext' ), $this->class_to_alias( $class ) ) );
		} else {
			$enabled[] = $class;
			update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, $enabled );
			/* translators: 1: user login, 2: user ID, 3: provider alias */
			WP_CLI::success( sprintf( __( 'Enabled %3$s for %1$s (ID: %2$d).', 'ws-two-factor-ext' ), $user->user_login, $user->ID, $this->class_to_alias( $class ) ) );
		}

		if ( isset( $assoc_args['set-primary'] ) ) {
			update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, $class );
			/* translators: %s: provider alias */
			WP_CLI::success( sprintf( __( 'Set %s as the primary provider.', 'ws-two-factor-ext' ), $this->class_to_alias( $class ) ) );
		}
	}

	// -----------------------------------------------------------------------
	// disable
	// -----------------------------------------------------------------------

	/**
	 * Disable a 2FA provider for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address.
	 *
	 * [--provider=<provider>]
	 * : Provider to disable (email, totp, backup, fido-u2f).
	 * Omit to disable all 2FA providers.
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
			// Disable all providers.
			delete_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY );
			delete_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY );
			/* translators: 1: user login, 2: user ID */
			WP_CLI::success( sprintf( __( 'Disabled all 2FA providers for %1$s (ID: %2$d).', 'ws-two-factor-ext' ), $user->user_login, $user->ID ) );
			return;
		}

		$class   = $this->resolve_provider_or_error( $assoc_args['provider'] );
		$enabled = $this->get_enabled_providers( $user );

		if ( ! in_array( $class, $enabled, true ) ) {
			/* translators: %s: provider alias */
			WP_CLI::warning( sprintf( __( '%s is not enabled.', 'ws-two-factor-ext' ), $this->class_to_alias( $class ) ) );
			return;
		}

		$enabled = array_values( array_diff( $enabled, array( $class ) ) );
		update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, $enabled );

		// Clear primary if it was the disabled provider.
		$primary = $this->get_primary_provider( $user );
		if ( $primary === $class ) {
			delete_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY );
			WP_CLI::line( '  ' . __( 'Primary provider also cleared.', 'ws-two-factor-ext' ) );
		}

		/* translators: 1: user login, 2: user ID, 3: provider alias */
		WP_CLI::success( sprintf( __( 'Disabled %3$s for %1$s (ID: %2$d).', 'ws-two-factor-ext' ), $user->user_login, $user->ID, $this->class_to_alias( $class ) ) );
	}

	// -----------------------------------------------------------------------
	// set-primary
	// -----------------------------------------------------------------------

	/**
	 * Set the primary 2FA provider for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address.
	 *
	 * --provider=<provider>
	 * : Provider to set as primary (email, totp, backup, fido-u2f).
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
			/* translators: %s: provider alias */
			WP_CLI::error( sprintf( __( '%s is not enabled. Enable it first with the enable command.', 'ws-two-factor-ext' ), $this->class_to_alias( $class ) ) );
		}

		update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, $class );
		/* translators: 1: user login, 2: user ID, 3: provider alias */
		WP_CLI::success( sprintf( __( 'Set primary provider of %1$s (ID: %2$d) to %3$s.', 'ws-two-factor-ext' ), $user->user_login, $user->ID, $this->class_to_alias( $class ) ) );
	}

	// -----------------------------------------------------------------------
	// set-enforce
	// -----------------------------------------------------------------------

	/**
	 * Save a 2FA enforcement rule applied automatically on new user creation.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<providers>]
	 * : Comma-separated providers to enforce (e.g. email,backup).
	 *
	 * [--primary=<provider>]
	 * : Primary provider (e.g. email).
	 *
	 * [--role=<roles>]
	 * : Target roles (comma-separated). Omit to apply to all roles.
	 *
	 * [--all]
	 * : After saving the rule, immediately apply it to all existing users.
	 *
	 * [--overwrite]
	 * : Used with --all: also overwrite users who already have 2FA configured.
	 *
	 * [--dry-run]
	 * : Used with --all: preview changes without applying.
	 *
	 * [--disable]
	 * : Delete the enforcement rule.
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
			WP_CLI::success( __( 'Enforcement rule deleted.', 'ws-two-factor-ext' ) );
			return;
		}

		if ( empty( $assoc_args['provider'] ) ) {
			WP_CLI::error( __( 'Please specify the --provider option.', 'ws-two-factor-ext' ) );
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
				WP_CLI::error( __( 'The provider specified in --primary must also be included in --provider.', 'ws-two-factor-ext' ) );
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
			WP_CLI::success( __( 'Enforcement rule saved.', 'ws-two-factor-ext' ) );
		} else {
			WP_CLI::line( WP_CLI::colorize( '%Y' . __( '[dry-run] Rule will not be saved.', 'ws-two-factor-ext' ) . '%n' ) );
		}

		/* translators: %s: comma-separated list of provider aliases */
		WP_CLI::line( sprintf( __( '  Providers  : %s', 'ws-two-factor-ext' ), implode( ', ', array_map( array( $this, 'class_to_alias' ), $classes ) ) ) );
		if ( $primary ) {
			/* translators: %s: provider alias */
			WP_CLI::line( sprintf( __( '  Primary    : %s', 'ws-two-factor-ext' ), $this->class_to_alias( $primary ) ) );
		}
		/* translators: %s: comma-separated list of roles, or "(all roles)" */
		WP_CLI::line( sprintf( __( '  Target roles: %s', 'ws-two-factor-ext' ), empty( $roles ) ? __( '(all roles)', 'ws-two-factor-ext' ) : implode( ', ', $roles ) ) );

		// --all: apply immediately to existing users.
		if ( isset( $assoc_args['all'] ) ) {
			WP_CLI::line( '' );
			$this->run_apply_enforce( $rule, $dry_run, isset( $assoc_args['overwrite'] ) );
		} else {
			WP_CLI::line( '  ' . __( 'Note: The rule will be auto-applied to new users on registration.', 'ws-two-factor-ext' ) );
			WP_CLI::line( '  ' . __( 'Note: To apply to existing users, run: wp 2fa-ex apply-enforce', 'ws-two-factor-ext' ) );
		}
	}

	// -----------------------------------------------------------------------
	// apply-enforce
	// -----------------------------------------------------------------------

	/**
	 * Apply the saved enforcement rule to existing users.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : Apply to a specific user only (ID, login name, or email).
	 *
	 * [--role=<role>]
	 * : Apply only to users with the specified role.
	 *
	 * [--overwrite]
	 * : Also overwrite users who already have 2FA configured.
	 *
	 * [--dry-run]
	 * : Preview changes without applying.
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
			WP_CLI::error( __( 'No enforcement rule is configured. Run wp 2fa-ex set-enforce first.', 'ws-two-factor-ext' ) );
		}

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
	 * Display the current enforcement rule.
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
			WP_CLI::line( __( 'No enforcement rule is configured.', 'ws-two-factor-ext' ) );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( __( '  Current enforcement rule', 'ws-two-factor-ext' ) );
		/* translators: %s: comma-separated list of provider aliases */
		WP_CLI::line( sprintf( __( '  Providers  : %s', 'ws-two-factor-ext' ), implode( ', ', array_map( array( $this, 'class_to_alias' ), $rule['providers'] ) ) ) );
		if ( ! empty( $rule['primary'] ) ) {
			/* translators: %s: provider alias */
			WP_CLI::line( sprintf( __( '  Primary    : %s', 'ws-two-factor-ext' ), $this->class_to_alias( $rule['primary'] ) ) );
		}
		/* translators: %s: comma-separated list of roles, or "(all roles)" */
		WP_CLI::line( sprintf( __( '  Target roles: %s', 'ws-two-factor-ext' ), empty( $rule['roles'] ) ? __( '(all roles)', 'ws-two-factor-ext' ) : implode( ', ', $rule['roles'] ) ) );
		WP_CLI::line( '' );
	}

	// -----------------------------------------------------------------------
	// lock-enable / lock-disable / lock-status
	// -----------------------------------------------------------------------

	/**
	 * Lock 2FA so non-admin users cannot disable it.
	 *
	 * Once enabled, non-admin users cannot remove their 2FA providers
	 * via the profile page or the REST API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex lock-enable
	 *
	 * @subcommand lock-enable
	 */
	public function lock_enable( array $args, array $assoc_args ): void {
		WS_Two_Factor_Lock::get_instance()->enable();
		WP_CLI::success( __( '2FA lock enabled. Non-admin users can no longer disable 2FA.', 'ws-two-factor-ext' ) );
	}

	/**
	 * Disable the 2FA lock.
	 *
	 * Users will be able to freely change their own 2FA settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp 2fa-ex lock-disable
	 *
	 * @subcommand lock-disable
	 */
	public function lock_disable( array $args, array $assoc_args ): void {
		WS_Two_Factor_Lock::get_instance()->disable();
		WP_CLI::success( __( '2FA lock disabled.', 'ws-two-factor-ext' ) );
	}

	/**
	 * Show the current 2FA lock status.
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
			$status = WP_CLI::colorize( '%G' . __( 'enabled', 'ws-two-factor-ext' ) . '%n' );
			/* translators: %s: colored status label */
			WP_CLI::line( sprintf( __( '  2FA lock: %s (non-admin users cannot disable 2FA)', 'ws-two-factor-ext' ), $status ) );
		} else {
			$status = WP_CLI::colorize( '%r' . __( 'disabled', 'ws-two-factor-ext' ) . '%n' );
			/* translators: %s: colored status label */
			WP_CLI::line( sprintf( __( '  2FA lock: %s', 'ws-two-factor-ext' ), $status ) );
		}
	}

	// -----------------------------------------------------------------------
	// Helper methods
	// -----------------------------------------------------------------------

	/**
	 * Run the enforcement rule against a list of users.
	 *
	 * @param array     $rule      Rule to apply.
	 * @param bool      $dry_run   When true, no changes are made.
	 * @param bool      $overwrite When true, overwrite users who already have 2FA.
	 * @param WP_User[] $users     Target users. All users if empty.
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
			WP_CLI::line( WP_CLI::colorize( '%Y' . __( '[dry-run] No changes will be made.', 'ws-two-factor-ext' ) . '%n' ) );
		}

		$applied = 0;
		$skipped = 0;

		foreach ( $users as $user ) {
			// Role filter (from the rule's role restriction).
			if ( ! empty( $rule['roles'] ) && ! array_intersect( $user->roles, $rule['roles'] ) ) {
				continue;
			}

			$enabled = $this->get_enabled_providers( $user );

			if ( ! $overwrite && ! empty( $enabled ) ) {
				/* translators: 1: user login, 2: user ID */
				WP_CLI::line( sprintf( __( '  Skipped: %1$s (ID: %2$d) — 2FA already configured.', 'ws-two-factor-ext' ), $user->user_login, $user->ID ) );
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::line(
					sprintf(
						/* translators: 1: user login, 2: user ID, 3: provider list, 4: primary info (or empty) */
						__( '  [dry-run] %1$s (ID: %2$d): enable %3$s%4$s', 'ws-two-factor-ext' ),
						$user->user_login,
						$user->ID,
						implode( ', ', array_map( array( $this, 'class_to_alias' ), $rule['providers'] ) ),
						$rule['primary'] ? sprintf(
							/* translators: %s: primary provider alias */
							__( ', Primary: %s', 'ws-two-factor-ext' ),
							$this->class_to_alias( $rule['primary'] )
						) : ''
					)
				);
			} else {
				$enforcement->apply_rule_to_user( $user, $rule );
				/* translators: 1: user login, 2: user ID */
				WP_CLI::line( sprintf( __( '  Applied: %1$s (ID: %2$d)', 'ws-two-factor-ext' ), $user->user_login, $user->ID ) );
			}

			++$applied;
		}

		WP_CLI::line( '' );
		if ( $dry_run ) {
			/* translators: 1: number of users to apply, 2: number to skip */
			WP_CLI::success( sprintf( __( '[dry-run] %1$d user(s) would be updated, %2$d skipped.', 'ws-two-factor-ext' ), $applied, $skipped ) );
		} else {
			/* translators: 1: number of users applied, 2: number skipped */
			WP_CLI::success( sprintf( __( 'Applied to %1$d user(s). %2$d skipped.', 'ws-two-factor-ext' ), $applied, $skipped ) );
		}
	}

	/**
	 * Retrieve a user or exit with an error.
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
			/* translators: %s: the identifier provided by the user */
			WP_CLI::error( sprintf( __( 'User not found: %s', 'ws-two-factor-ext' ), $identifier ) );
		}

		return $user;
	}

	/**
	 * Resolve a provider alias to its class name, or exit with an error.
	 */
	private function resolve_provider_or_error( string $alias ): string {
		if ( empty( $alias ) ) {
			$available = implode( ', ', array_keys( self::PROVIDER_ALIASES ) );
			/* translators: %s: list of available provider aliases */
			WP_CLI::error( sprintf( __( 'Please specify --provider. Available: %s', 'ws-two-factor-ext' ), $available ) );
		}

		// Match by alias.
		if ( isset( self::PROVIDER_ALIASES[ $alias ] ) ) {
			return self::PROVIDER_ALIASES[ $alias ];
		}

		// Accept full class names as well.
		$providers = Two_Factor_Core::get_providers();
		if ( isset( $providers[ $alias ] ) ) {
			return $alias;
		}

		WP_CLI::error(
			sprintf(
				/* translators: 1: the unknown provider name entered by the user, 2: comma-separated list of valid aliases */
				__( 'Unknown provider: "%1$s". Available: %2$s', 'ws-two-factor-ext' ),
				$alias,
				implode( ', ', array_keys( self::PROVIDER_ALIASES ) )
			)
		);
	}

	/**
	 * Convert a provider class name to its alias (for display).
	 */
	private function class_to_alias( string $class ): string {
		$flipped = array_flip( self::PROVIDER_ALIASES );
		return $flipped[ $class ] ?? $class;
	}

	/**
	 * Get the list of enabled providers for a user.
	 *
	 * @return string[]
	 */
	private function get_enabled_providers( WP_User $user ): array {
		$meta = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Get the primary provider class name for a user.
	 */
	private function get_primary_provider( WP_User $user ): string {
		return (string) get_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, true );
	}
}
