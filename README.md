# WS Two Factor Extension

English | [日本語](README.ja.md)

A WordPress plugin to manage and enforce [Two Factor](https://wordpress.org/plugins/two-factor/) settings via WP-CLI.

## Features

- List and check 2FA status for all users
- Enable / disable / set primary provider per user via WP-CLI
- Save enforcement rules and auto-apply them on new user creation
- Bulk-apply rules to existing users (`--all` / `apply-enforce`)
- **[v1.1.0]** Lock feature: prevent non-admin users from disabling their own 2FA

## Requirements

| Item | Version |
|---|---|
| WordPress | 6.8+ |
| PHP | 7.2+ |
| [Two Factor](https://wordpress.org/plugins/two-factor/) | Must be activated |

## Installation

1. Place the `ws-two-factor-ext` folder inside `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin dashboard.
3. If the Two Factor plugin is not activated, a warning will appear in the admin dashboard.

## WP-CLI Commands

All commands start with `wp 2fa-ex`.

---

### `wp 2fa-ex list` — List Users

Displays the 2FA configuration status of all users in a table.

```bash
wp 2fa-ex list
wp 2fa-ex list --role=subscriber
wp 2fa-ex list --enabled-only
wp 2fa-ex list --format=csv
```

| Option | Description |
|---|---|
| `--role=<role>` | Filter by role |
| `--enabled-only` | Show only users with 2FA enabled |
| `--fields=<fields>` | Comma-separated list of fields to display |
| `--format=<format>` | Output format: `table` (default) / `csv` / `json` / `yaml` |

---

### `wp 2fa-ex status <user>` — Detailed Status

Shows detailed 2FA provider status for a specific user.
The user can be specified by ID, login name, or email address.

```bash
wp 2fa-ex status admin
wp 2fa-ex status 1
wp 2fa-ex status user@example.com
```

---

### `wp 2fa-ex enable <user> --provider=<provider>` — Enable Provider

Enables a 2FA provider for a user.

```bash
wp 2fa-ex enable admin --provider=email
wp 2fa-ex enable admin --provider=totp --set-primary
```

| Option | Description |
|---|---|
| `--provider=<provider>` | `email` / `totp` / `backup` / `fido-u2f` |
| `--set-primary` | Also set the enabled provider as Primary |

---

### `wp 2fa-ex disable <user>` — Disable Provider

Disables a 2FA provider for a user.
Omitting `--provider` disables all providers.

```bash
wp 2fa-ex disable admin --provider=email   # disable email only
wp 2fa-ex disable admin                    # disable all providers
```

| Option | Description |
|---|---|
| `--provider=<provider>` | Provider to disable. Omit to disable all |

---

### `wp 2fa-ex set-primary <user> --provider=<provider>` — Set Primary

Changes the primary 2FA provider.
The target provider must already be enabled.

```bash
wp 2fa-ex set-primary admin --provider=totp
```

---

### `wp 2fa-ex set-enforce` — Save Enforcement Rule

Saves an enforcement rule that is automatically applied when a new user is created.
Add `--all` to also apply immediately to existing users.

```bash
# Force email for subscriber role
wp 2fa-ex set-enforce --provider=email --role=subscriber

# Force email + backup for all roles, set email as Primary
wp 2fa-ex set-enforce --provider=email,backup --primary=email

# Save rule + immediately apply to all existing users
wp 2fa-ex set-enforce --provider=email --all

# Check what would be applied before running for real
wp 2fa-ex set-enforce --provider=email --all --dry-run
wp 2fa-ex set-enforce --provider=email --all

# Remove enforcement rule
wp 2fa-ex set-enforce --disable
```

| Option | Description |
|---|---|
| `--provider=<providers>` | Providers to enforce (comma-separated) |
| `--primary=<provider>` | Primary provider |
| `--role=<roles>` | Target roles (comma-separated; omit for all roles) |
| `--all` | After saving rule, immediately apply to all existing users |
| `--overwrite` | With `--all`: also overwrite users who already have 2FA configured |
| `--dry-run` | With `--all`: preview changes without applying |
| `--disable` | Delete the enforcement rule |

---

### `wp 2fa-ex apply-enforce` — Bulk Apply Enforcement Rule

Applies the saved enforcement rule to existing users.
By default, users who already have 2FA configured are skipped.

```bash
wp 2fa-ex apply-enforce
wp 2fa-ex apply-enforce --role=subscriber
wp 2fa-ex apply-enforce --dry-run
wp 2fa-ex apply-enforce --overwrite
wp 2fa-ex apply-enforce --user=john
```

| Option | Description |
|---|---|
| `--user=<user>` | Apply to a specific user only |
| `--role=<role>` | Apply only to users with the specified role |
| `--overwrite` | Also overwrite users who already have 2FA configured |
| `--dry-run` | Preview changes without applying |

---

### `wp 2fa-ex show-enforce` — Show Enforcement Rule

Displays the currently saved enforcement rule.

```bash
wp 2fa-ex show-enforce
```

---

### `wp 2fa-ex lock-enable` — Enable 2FA Lock

Prevents non-admin users from disabling their own 2FA.

Once enabled, 2FA removal is blocked via:
- Unchecking providers on the profile page (form submission)
- REST API (`DELETE /wp-json/two-factor/1.0/totp`, etc.)

A warning is shown on the profile page of locked users who have 2FA configured.

```bash
wp 2fa-ex lock-enable
```

> Users with the `manage_options` capability (administrators) are not restricted.

---

### `wp 2fa-ex lock-disable` — Disable 2FA Lock

Disables the 2FA lock, allowing users to freely modify their own 2FA settings.

```bash
wp 2fa-ex lock-disable
```

---

### `wp 2fa-ex lock-status` — Check Lock Status

Displays the current 2FA lock state.

```bash
wp 2fa-ex lock-status
```

---

## Provider Names

| Alias | Description |
|---|---|
| `email` | One-time password via email |
| `totp` | Authenticator app (Google Authenticator, etc.) |
| `backup` | Backup codes |
| `fido-u2f` | FIDO U2F / YubiKey |

## Changelog

| Version | Changes |
|---|---|
| 1.2.0 | Added i18n support — all user-facing strings are now translatable |
| 1.1.0 | Added non-admin 2FA lock feature (`lock-enable` / `lock-disable` / `lock-status`) |
| 1.0.0 | Initial release |

## Typical Workflows

### Force 2FA for all users on a new site

```bash
# 1. Force email for subscriber role and immediately apply to existing users
wp 2fa-ex set-enforce --provider=email --role=subscriber --all

# 2. Verify the result
wp 2fa-ex list --role=subscriber
```

### Manually configure 2FA for a specific user

```bash
# Enable TOTP and set it as primary
wp 2fa-ex enable john --provider=totp --set-primary

# Also add backup codes
wp 2fa-ex enable john --provider=backup

# Verify the configuration
wp 2fa-ex status john
```

### Update enforcement rule and re-apply

```bash
# Check the current rule
wp 2fa-ex show-enforce

# Change the rule (dry-run first, then apply)
wp 2fa-ex set-enforce --provider=email,backup --primary=email --all --dry-run
wp 2fa-ex set-enforce --provider=email,backup --primary=email --all --overwrite
```

### Prevent users from disabling their 2FA

```bash
# Enable lock (non-admin users can no longer disable 2FA)
wp 2fa-ex lock-enable

# Check status
wp 2fa-ex lock-status

# Disable lock if needed
wp 2fa-ex lock-disable
```
