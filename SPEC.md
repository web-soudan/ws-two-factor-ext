# WS Two Factor Extension — 仕様書

Two Factor プラグイン (`wordpress/two-factor`) の機能を WP-CLI から操作・強制適用するための拡張プラグイン。

---

## ファイル構成

```
ws-two-factor-ext/
├── ws-two-factor-ext.php                      # メインファイル
├── SPEC.md
└── includes/
    ├── class-ws-two-factor-cli.php            # WP-CLI コマンド群
    └── class-ws-two-factor-enforcement.php   # 強制適用ロジック
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
