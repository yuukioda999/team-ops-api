# 機能仕様(第1弾)

仕様駆動開発の流れ:spec.md(何を作るか)→ plan.md(どう作るか)→ tasks.md(作業分解)→ 実装。
この段階では spec.md だけを書く。plan.md と tasks.md は、spec のレビュー後に機能ごとに作る。

| No. | 機能 | 仕様 |
|---|---|---|
| 001 | アカウントとチーム作成 | [001-account-and-team/spec.md](001-account-and-team/spec.md) |
| 002 | 招待と参加承認 | [002-invitation/spec.md](002-invitation/spec.md) |
| 003 | 名簿 | [003-roster/spec.md](003-roster/spec.md) |
| 004 | 役割と権限 | [004-roles-and-permissions/spec.md](004-roles-and-permissions/spec.md) |
| 005 | お知らせ | [005-notice/spec.md](005-notice/spec.md) |
| 006 | 既読・配信状況・再通知 | [006-read-and-delivery-status/spec.md](006-read-and-delivery-status/spec.md) |
| 007 | デモモード | [007-demo-mode/spec.md](007-demo-mode/spec.md) |

notify-relay(Go)側の仕様は [notify-relay の specs/](https://github.com/yuukioda999/notify-relay/tree/main/specs) にある。

## 受け入れ基準の書き方

- IDは `AC-機能番号-連番`(例:AC-002-03)
- 1つの受け入れ基準を、1つ以上の自動テストで確認する。テスト名に受け入れ基準のIDを含める
- 書き方は「前提 / 操作 / 結果」

## 全機能に共通する受け入れ基準

| ID | 前提 | 操作 | 結果 |
|---|---|---|---|
| AC-000-01 | チームAのメンバーである | チームBの任意のデータ(ID指定)をAPIで取得・変更しようとする | 404を返す。存在の有無も分からないようにする |
| AC-000-02 | ログインしていない | ログインが必要なAPIを呼ぶ | 401を返す |
| AC-000-03 | 権限のない役割である | その操作のAPIを呼ぶ | 403を返し、データは変わらない |
| AC-000-04 | 任意 | 個人情報(表示名・メールアドレス)を含む処理でエラーが起きる | アプリのログとエラー通知に個人情報が出力されない |
| AC-000-05 | 任意 | 入力値が仕様に合わない | 422を返し、項目ごとのエラー内容を返す |
