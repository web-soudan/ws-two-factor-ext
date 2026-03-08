# WS Two Factor Extension

[Two Factor](https://wordpress.org/plugins/two-factor/) プラグインを WP-CLI から管理・強制適用するための拡張プラグインです。

## 機能

- ユーザーごとの 2FA 設定状況を一覧表示・確認
- WP-CLI からプロバイダーの有効化 / 無効化 / Primary 設定
- 強制ルールを保存し、新規ユーザー作成時に自動適用
- 既存ユーザーへの一括適用 (`--all` / `apply-enforce`)

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

## プロバイダー名

| エイリアス | 概要 |
|---|---|
| `email` | メール送信 OTP |
| `totp` | 認証アプリ (Google Authenticator 等) |
| `backup` | バックアップコード |
| `fido-u2f` | FIDO U2F / YubiKey |

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
