# WS Two Factor Extension — Specification

An extension plugin for the Two Factor plugin (`wordpress/two-factor`) to manage and enforce 2FA settings via WP-CLI.

| Version | Changes |
|---|---|
| 1.1.0 | Added non-admin 2FA lock feature |
| 1.0.0 | Initial release |

---

## File Structure

```
ws-two-factor-ext/
├── ws-two-factor-ext.php                      # Main plugin file
├── SPEC.md
├── README.md
└── includes/
    ├── class-ws-two-factor-cli.php            # WP-CLI commands
    ├── class-ws-two-factor-enforcement.php   # Enforcement logic
    └── class-ws-two-factor-lock.php          # Non-admin 2FA lock
```

---

## Dependencies

- WordPress 6.8+
- Two Factor plugin (`two-factor`) must be activated

---

## WP-CLI Command Reference

Command prefix: `wp 2fa-ex`

### `wp 2fa-ex list`

Lists the 2FA configuration status of all users.

| Option | Description |
|---|---|
| `--role=<role>` | Filter to users with the specified role |
| `--enabled-only` | Show only users with 2FA enabled |
| `--fields=<fields>` | Comma-separated list of fields to display |
| `--format=<format>` | Output format: table / csv / json / yaml |

**Examples:**
```bash
wp 2fa-ex list
wp 2fa-ex list --role=subscriber --format=csv
wp 2fa-ex list --enabled-only
```

---

### `wp 2fa-ex status <user>`

Displays detailed 2FA status for a specific user.

**Examples:**
```bash
wp 2fa-ex status admin
wp 2fa-ex status 1
wp 2fa-ex status user@example.com
```

---

### `wp 2fa-ex enable <user> --provider=<provider>`

Enables a 2FA provider for a user.

| Option | Description |
|---|---|
| `--provider=<provider>` | `email` / `totp` / `backup` / `fido-u2f` |
| `--set-primary` | Also set the enabled provider as Primary |

**Examples:**
```bash
wp 2fa-ex enable admin --provider=email
wp 2fa-ex enable admin --provider=totp --set-primary
```

---

### `wp 2fa-ex disable <user> [--provider=<provider>]`

Disables a 2FA provider for a user. Omitting `--provider` disables all providers.

**Examples:**
```bash
wp 2fa-ex disable admin --provider=email
wp 2fa-ex disable admin          # disable all providers
```

---

### `wp 2fa-ex set-primary <user> --provider=<provider>`

Sets the primary 2FA provider. The target provider must already be enabled.

**Examples:**
```bash
wp 2fa-ex set-primary admin --provider=totp
```

---

### `wp 2fa-ex set-enforce`

Saves an enforcement rule that is automatically applied when a new user is created.
The rule is stored in `wp_options` under `ws_2fa_enforcement_rule`.

| Option | Description |
|---|---|
| `--provider=<providers>` | Comma-separated providers (e.g. `email,backup`) |
| `--primary=<provider>` | Primary provider |
| `--role=<roles>` | Target roles (comma-separated; omit for all roles) |
| `--all` | After saving rule, immediately apply to all existing users |
| `--overwrite` | With `--all`: also overwrite users who already have 2FA configured |
| `--dry-run` | With `--all`: preview changes without applying |
| `--disable` | Delete the enforcement rule |

**Examples:**
```bash
wp 2fa-ex set-enforce --provider=email --role=subscriber
wp 2fa-ex set-enforce --provider=email,backup --primary=email
wp 2fa-ex set-enforce --provider=email --all
wp 2fa-ex set-enforce --provider=email --all --dry-run
wp 2fa-ex set-enforce --disable
```

---

### `wp 2fa-ex apply-enforce`

Applies the saved enforcement rule to existing users.

| Option | Description |
|---|---|
| `--user=<user>` | Apply to a specific user only |
| `--role=<role>` | Apply only to users with the specified role |
| `--overwrite` | Also overwrite users who already have 2FA configured |
| `--dry-run` | Preview changes without applying |

**Examples:**
```bash
wp 2fa-ex apply-enforce
wp 2fa-ex apply-enforce --role=subscriber
wp 2fa-ex apply-enforce --dry-run
wp 2fa-ex apply-enforce --overwrite
```

---

### `wp 2fa-ex show-enforce`

Displays the currently saved enforcement rule.

**Examples:**
```bash
wp 2fa-ex show-enforce
```

---

### `wp 2fa-ex lock-enable`

Prevents non-admin users from disabling their own 2FA.
The lock state is saved in `wp_options` under `ws_2fa_lock_enabled`.

**Blocked actions:**
- Removing providers via the profile page (form submission)
- Removing providers via REST API (`DELETE /wp-json/two-factor/1.0/totp`, etc.)

**Not restricted (admins are exempt):**
- Users with the `manage_options` capability

**Profile page changes:**
- A warning notice is shown on the profile page of non-admin users who have 2FA configured

**Examples:**
```bash
wp 2fa-ex lock-enable
```

---

### `wp 2fa-ex lock-disable`

Disables the 2FA lock.

**Examples:**
```bash
wp 2fa-ex lock-disable
```

---

### `wp 2fa-ex lock-status`

Displays the current 2FA lock state.

**Examples:**
```bash
wp 2fa-ex lock-status
```

---

## Provider Names

| Alias | Class Name | Description |
|---|---|---|
| `email` | `Two_Factor_Email` | One-time password via email |
| `totp` | `Two_Factor_Totp` | Authenticator app (TOTP) |
| `backup` | `Two_Factor_Backup_Codes` | Backup codes |
| `fido-u2f` | `Two_Factor_FIDO_U2F` | FIDO U2F / YubiKey |

---

## Enforcement Logic

1. Save a rule via `wp 2fa-ex set-enforce` — stored in `wp_options`
2. `user_register` hook auto-applies the rule on new user creation
3. `wp 2fa-ex apply-enforce` can bulk-apply to existing users
4. Users who already have 2FA configured are skipped by default (`--overwrite` to override)
5. Enforcement providers are **merged** into existing configuration (existing providers are not removed)

---

## Lock Logic

1. `wp 2fa-ex lock-enable` saves lock state to `wp_options`
2. `personal_options_update` hook at priority **5** runs before Two Factor's save handler (priority 10), forcibly merging existing providers into `$_POST` to prevent removal
3. `two_factor_rest_api_can_edit_user` filter rejects REST API DELETE requests with HTTP 403
4. Lock is disabled by default; must be explicitly enabled via `lock-enable`
5. Users without 2FA configured are not affected (initial setup remains unrestricted)
6. Non-admin users cannot **remove** existing providers, but can still **add** new ones

---

## Data Storage

User meta keys used by the Two Factor plugin:

| Meta Key | Content |
|---|---|
| `_two_factor_enabled_providers` | Array of enabled provider class names |
| `_two_factor_provider` | Primary provider class name |

Options used by this plugin:

| Option Key | Content |
|---|---|
| `ws_2fa_enforcement_rule` | Enforcement rule (providers, primary, roles) |
| `ws_2fa_lock_enabled` | 2FA lock state (bool) |

---

---

# WS Two Factor Extension — 仕様書

Two Factor プラグイン (`wordpress/two-factor`) の機能を WP-CLI から操作・強制適用するための拡張プラグイン。

| バージョン | 変更内容 |
|---|---|
| 1.1.0 | 非管理者による 2FA 無効化ロック機能を追加 |
| 1.0.0 | 初回リリース |

---

## ファイル構成

```
ws-two-factor-ext/
├── ws-two-factor-ext.php                      # メインファイル
├── SPEC.md
├── README.md
└── includes/
    ├── class-ws-two-factor-cli.php            # WP-CLI コマンド群
    ├── class-ws-two-factor-enforcement.php   # 強制適用ロジック
    └── class-ws-two-factor-lock.php          # 非管理者 2FA ロック
```

---

## 依存関係

- WordPress 6.8+
- Two Factor プラグイン (`two-factor`) が有効化されていること

---

## WP-CLI コマンド一覧

コマンドのプレフィックスは `wp 2fa-ex`。

### `wp 2fa-ex list`

全ユーザーの 2FA 設定状況を一覧表示する。

| オプション | 説明 |
|---|---|
| `--role=<role>` | 指定ロールのユーザーに絞り込む |
| `--enabled-only` | 2FA が有効なユーザーのみ表示 |
| `--fields=<fields>` | 表示フィールドをカンマ区切りで指定 |
| `--format=<format>` | 出力形式 (table / csv / json / yaml) |

**例:**
```bash
wp 2fa-ex list
wp 2fa-ex list --role=subscriber --format=csv
wp 2fa-ex list --enabled-only
```

---

### `wp 2fa-ex status <user>`

指定ユーザーの 2FA 詳細ステータスを表示する。

**例:**
```bash
wp 2fa-ex status admin
wp 2fa-ex status 1
wp 2fa-ex status user@example.com
```

---

### `wp 2fa-ex enable <user> --provider=<provider>`

ユーザーの 2FA プロバイダーを有効化する。

| オプション | 説明 |
|---|---|
| `--provider=<provider>` | `email` / `totp` / `backup` / `fido-u2f` |
| `--set-primary` | 有効化したプロバイダーを Primary にも設定 |

**例:**
```bash
wp 2fa-ex enable admin --provider=email
wp 2fa-ex enable admin --provider=totp --set-primary
```

---

### `wp 2fa-ex disable <user> [--provider=<provider>]`

ユーザーの 2FA プロバイダーを無効化する。`--provider` 省略時は全プロバイダーを無効化。

**例:**
```bash
wp 2fa-ex disable admin --provider=email
wp 2fa-ex disable admin          # 全プロバイダー無効化
```

---

### `wp 2fa-ex set-primary <user> --provider=<provider>`

Primary 2FA プロバイダーを設定する。対象プロバイダーが有効化済みである必要がある。

**例:**
```bash
wp 2fa-ex set-primary admin --provider=totp
```

---

### `wp 2fa-ex set-enforce`

新規ユーザー作成時に自動適用する強制ルールを設定する。
ルールは `wp_options` の `ws_2fa_enforcement_rule` に保存される。

| オプション | 説明 |
|---|---|
| `--provider=<providers>` | カンマ区切りのプロバイダー (例: `email,backup`) |
| `--primary=<provider>` | Primary プロバイダー |
| `--role=<roles>` | 適用対象ロール (カンマ区切り。省略時は全ロール) |
| `--all` | ルール保存後、既存の全ユーザーへ即時適用 |
| `--overwrite` | `--all` と組み合わせ: 設定済みユーザーにも上書き |
| `--dry-run` | `--all` と組み合わせ: 実際には変更せず確認のみ |
| `--disable` | 強制ルールを削除する |

**例:**
```bash
wp 2fa-ex set-enforce --provider=email --role=subscriber
wp 2fa-ex set-enforce --provider=email,backup --primary=email
wp 2fa-ex set-enforce --provider=email --all
wp 2fa-ex set-enforce --provider=email --all --dry-run
wp 2fa-ex set-enforce --disable
```

---

### `wp 2fa-ex apply-enforce`

保存済みの強制ルールを既存ユーザーに適用する。

| オプション | 説明 |
|---|---|
| `--user=<user>` | 特定ユーザーのみに適用 |
| `--role=<role>` | 指定ロールのユーザーに適用 |
| `--overwrite` | すでに 2FA が設定済みのユーザーにも上書き |
| `--dry-run` | 変更せずに適用予定内容を表示 |

**例:**
```bash
wp 2fa-ex apply-enforce
wp 2fa-ex apply-enforce --role=subscriber
wp 2fa-ex apply-enforce --dry-run
wp 2fa-ex apply-enforce --overwrite
```

---

### `wp 2fa-ex show-enforce`

現在保存されている強制ルールを表示する。

**例:**
```bash
wp 2fa-ex show-enforce
```

---

### `wp 2fa-ex lock-enable`

非管理者ユーザーが自分の 2FA を無効化できないようにロックする。
有効化するとロック状態が `wp_options` の `ws_2fa_lock_enabled` に保存される。

**ブロック対象:**
- プロフィール画面からのプロバイダー削除 (フォーム送信)
- REST API (`DELETE /wp-json/two-factor/1.0/totp` 等) からの削除

**対象外 (管理者は制限なし):**
- `manage_options` 権限を持つユーザー

**プロフィール画面の変化:**
- 2FA 設定済みの非管理者のプロフィール画面にロック中の警告を表示

**例:**
```bash
wp 2fa-ex lock-enable
```

---

### `wp 2fa-ex lock-disable`

2FA ロックを解除する。

**例:**
```bash
wp 2fa-ex lock-disable
```

---

### `wp 2fa-ex lock-status`

現在の 2FA ロック状態を表示する。

**例:**
```bash
wp 2fa-ex lock-status
```

---

## プロバイダー名一覧

| エイリアス | クラス名 | 概要 |
|---|---|---|
| `email` | `Two_Factor_Email` | メール送信 OTP |
| `totp` | `Two_Factor_Totp` | 認証アプリ (TOTP) |
| `backup` | `Two_Factor_Backup_Codes` | バックアップコード |
| `fido-u2f` | `Two_Factor_FIDO_U2F` | FIDO U2F / YubiKey |

---

## 強制適用ロジック

1. `wp 2fa-ex set-enforce` でルールを `wp_options` に保存
2. `user_register` フックで新規ユーザー作成時に自動適用
3. `wp 2fa-ex apply-enforce` で既存ユーザーへも一括適用可能
4. 既存の 2FA 設定がある場合はデフォルトでスキップ (`--overwrite` で上書き)
5. 強制ルールのプロバイダーは既存設定にマージされる（削除しない）

---

## Lock ロジック

1. `wp 2fa-ex lock-enable` でロック状態を `wp_options` に保存
2. `personal_options_update` フック (priority **5**) で Two Factor の保存処理 (priority 10) より前に実行し、
   `$_POST` に既存プロバイダーを強制マージすることで削除を防止
3. `two_factor_rest_api_can_edit_user` フィルターで REST API の DELETE リクエストを 403 で拒否
4. デフォルトは無効。`lock-enable` で明示的に有効化が必要
5. 2FA 未設定のユーザーはロック対象外（初回設定は自由に行える）
6. 非管理者は既存プロバイダーの削除は不可だが、**追加は許可**される

---

## データストレージ

Two Factor プラグインが使用する User Meta キー:

| メタキー | 内容 |
|---|---|
| `_two_factor_enabled_providers` | 有効なプロバイダーの配列 |
| `_two_factor_provider` | Primary プロバイダーのクラス名 |

本プラグインが使用するオプションキー:

| オプションキー | 内容 |
|---|---|
| `ws_2fa_enforcement_rule` | 強制ルール (providers, primary, roles) |
| `ws_2fa_lock_enabled` | 2FA ロック状態 (bool) |
