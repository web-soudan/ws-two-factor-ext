# WS Two Factor Extension

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

---

---

# WS Two Factor Extension（日本語）

[Two Factor](https://wordpress.org/plugins/two-factor/) プラグインを WP-CLI から管理・強制適用するための拡張プラグインです。

## 機能

- ユーザーごとの 2FA 設定状況を一覧表示・確認
- WP-CLI からプロバイダーの有効化 / 無効化 / Primary 設定
- 強制ルールを保存し、新規ユーザー作成時に自動適用
- 既存ユーザーへの一括適用 (`--all` / `apply-enforce`)
- **[v1.1.0]** 非管理者による 2FA 無効化のロック機能

## 要件

| 項目 | バージョン |
|---|---|
| WordPress | 6.8 以上 |
| PHP | 7.2 以上 |
| [Two Factor](https://wordpress.org/plugins/two-factor/) | 有効化済みであること |

## インストール

1. `ws-two-factor-ext` フォルダを `wp-content/plugins/` に配置します。
2. WordPress 管理画面でプラグインを有効化します。
3. Two Factor プラグインが有効化されていない場合は管理画面に警告が表示されます。

## WP-CLI コマンド

すべてのコマンドは `wp 2fa-ex` から始まります。

---

### `wp 2fa-ex list` — ユーザー一覧

全ユーザーの 2FA 設定状況をテーブル形式で表示します。

```bash
wp 2fa-ex list
wp 2fa-ex list --role=subscriber
wp 2fa-ex list --enabled-only
wp 2fa-ex list --format=csv
```

| オプション | 説明 |
|---|---|
| `--role=<role>` | 指定ロールのユーザーのみ表示 |
| `--enabled-only` | 2FA が有効なユーザーのみ表示 |
| `--fields=<fields>` | 表示フィールドをカンマ区切りで指定 |
| `--format=<format>` | 出力形式 `table`(デフォルト) / `csv` / `json` / `yaml` |

---

### `wp 2fa-ex status <user>` — 詳細ステータス

指定ユーザーのプロバイダー設定状況を詳細表示します。
ユーザーは ID・ログイン名・メールアドレスのいずれかで指定できます。

```bash
wp 2fa-ex status admin
wp 2fa-ex status 1
wp 2fa-ex status user@example.com
```

---

### `wp 2fa-ex enable <user> --provider=<provider>` — 有効化

ユーザーの 2FA プロバイダーを有効化します。

```bash
wp 2fa-ex enable admin --provider=email
wp 2fa-ex enable admin --provider=totp --set-primary
```

| オプション | 説明 |
|---|---|
| `--provider=<provider>` | `email` / `totp` / `backup` / `fido-u2f` |
| `--set-primary` | 有効化したプロバイダーを Primary にも設定する |

---

### `wp 2fa-ex disable <user>` — 無効化

ユーザーの 2FA プロバイダーを無効化します。
`--provider` を省略すると 2FA を全て無効化します。

```bash
wp 2fa-ex disable admin --provider=email   # email のみ無効化
wp 2fa-ex disable admin                    # 全プロバイダーを無効化
```

| オプション | 説明 |
|---|---|
| `--provider=<provider>` | 無効化するプロバイダー。省略時は全無効化 |

---

### `wp 2fa-ex set-primary <user> --provider=<provider>` — Primary 設定

Primary 2FA プロバイダーを変更します。
対象プロバイダーが有効化済みである必要があります。

```bash
wp 2fa-ex set-primary admin --provider=totp
```

---

### `wp 2fa-ex set-enforce` — 強制ルールの設定

新規ユーザー作成時に自動適用する強制ルールを保存します。
`--all` を付けると既存ユーザーへも即時適用します。

```bash
# subscriber ロールに email を強制
wp 2fa-ex set-enforce --provider=email --role=subscriber

# 全ロールに email + backup を強制し、email を Primary に設定
wp 2fa-ex set-enforce --provider=email,backup --primary=email

# ルール保存 + 既存の全ユーザーに即時適用
wp 2fa-ex set-enforce --provider=email --all

# dry-run で適用予定を確認してから実行
wp 2fa-ex set-enforce --provider=email --all --dry-run
wp 2fa-ex set-enforce --provider=email --all

# 強制ルールを削除
wp 2fa-ex set-enforce --disable
```

| オプション | 説明 |
|---|---|
| `--provider=<providers>` | 強制するプロバイダー (カンマ区切り可) |
| `--primary=<provider>` | Primary プロバイダー |
| `--role=<roles>` | 適用対象ロール (カンマ区切り可。省略時は全ロール) |
| `--all` | ルール保存後、既存の全ユーザーへ即時適用 |
| `--overwrite` | `--all` と組み合わせ: 設定済みユーザーにも上書き |
| `--dry-run` | `--all` と組み合わせ: 変更せず適用予定を確認のみ |
| `--disable` | 強制ルールを削除する |

---

### `wp 2fa-ex apply-enforce` — 強制ルールの一括適用

保存済みの強制ルールを既存ユーザーに適用します。
デフォルトでは 2FA 設定済みのユーザーはスキップします。

```bash
wp 2fa-ex apply-enforce
wp 2fa-ex apply-enforce --role=subscriber
wp 2fa-ex apply-enforce --dry-run
wp 2fa-ex apply-enforce --overwrite
wp 2fa-ex apply-enforce --user=john
```

| オプション | 説明 |
|---|---|
| `--user=<user>` | 特定ユーザーのみに適用 |
| `--role=<role>` | 指定ロールのユーザーにのみ適用 |
| `--overwrite` | 設定済みユーザーにも上書き適用 |
| `--dry-run` | 変更せず適用予定の内容を表示 |

---

### `wp 2fa-ex show-enforce` — 強制ルールの確認

現在保存されている強制ルールを表示します。

```bash
wp 2fa-ex show-enforce
```

---

### `wp 2fa-ex lock-enable` — 2FA ロックの有効化

非管理者ユーザーが自分の 2FA を無効化できないようにロックします。

有効化すると以下の経路での 2FA 削除がブロックされます。
- プロフィール画面からのプロバイダーのチェック解除
- REST API (`DELETE /wp-json/two-factor/1.0/totp` 等) からの削除

ロック中のユーザーのプロフィール画面には警告が表示されます。

```bash
wp 2fa-ex lock-enable
```

> 管理者 (`manage_options` 権限) は引き続き変更可能です。

---

### `wp 2fa-ex lock-disable` — 2FA ロックの解除

2FA ロックを解除し、ユーザーが自分の 2FA を自由に変更できる状態に戻します。

```bash
wp 2fa-ex lock-disable
```

---

### `wp 2fa-ex lock-status` — 2FA ロックの状態確認

現在の 2FA ロック状態を表示します。

```bash
wp 2fa-ex lock-status
```

---

## プロバイダー名

| エイリアス | 概要 |
|---|---|
| `email` | メール送信 OTP |
| `totp` | 認証アプリ (Google Authenticator 等) |
| `backup` | バックアップコード |
| `fido-u2f` | FIDO U2F / YubiKey |

## バージョン履歴

| バージョン | 変更内容 |
|---|---|
| 1.1.0 | 非管理者による 2FA 無効化ロック機能を追加 (`lock-enable` / `lock-disable` / `lock-status`) |
| 1.0.0 | 初回リリース |

## 典型的なワークフロー

### 新規サイト立ち上げ時に全ユーザーへ 2FA を強制する

```bash
# 1. subscriber ロールに email を強制し、既存ユーザーへも即時適用
wp 2fa-ex set-enforce --provider=email --role=subscriber --all

# 2. 設定後の状態を確認
wp 2fa-ex list --role=subscriber
```

### 特定ユーザーの 2FA を手動で設定する

```bash
# TOTP を有効化して Primary に設定
wp 2fa-ex enable john --provider=totp --set-primary

# バックアップコードも追加
wp 2fa-ex enable john --provider=backup

# 設定結果を確認
wp 2fa-ex status john
```

### 強制ルールを変更して再適用する

```bash
# 現在のルールを確認
wp 2fa-ex show-enforce

# ルールを変更（dry-run で確認してから本番実行）
wp 2fa-ex set-enforce --provider=email,backup --primary=email --all --dry-run
wp 2fa-ex set-enforce --provider=email,backup --primary=email --all --overwrite
```

### 2FA 設定をユーザーが勝手に外せないようにする

```bash
# ロックを有効化（以降、非管理者は 2FA を無効化できなくなる）
wp 2fa-ex lock-enable

# 状態確認
wp 2fa-ex lock-status

# ロックを解除する場合
wp 2fa-ex lock-disable
```
