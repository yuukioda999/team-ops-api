# team-ops-api

少年サッカーチーム運営ツールのバックエンドAPI(Laravel 13、API専用)。
要件・全体のADR・機能仕様は ../team-ops にある。作業前に ../team-ops/CLAUDE.md と該当する spec を読むこと。

## コマンド

- テスト:`php artisan test`(ローカルは `make test-api` を ../team-ops から)
- 整形:`vendor/bin/pint`
- 静的解析:`vendor/bin/phpstan analyse`(Larastan、レベル8)

## 設計ルール

- 層:Controller(入出力だけ)→ UseCase(業務処理、1ユースケース1クラス)→ Model。Controllerから直接クエリを書かない
- 権限:判定は Policy に集約する。Controllerに役割の条件分岐を書かない
- マルチテナント:チームに属するデータへのクエリには、必ずチームIDの絞り込みをかける。他チームのデータは404を返す(AC-000-01)
- 個人情報:表示名・メールアドレスは暗号化して保存(ADR-0003)。ログ・例外通知に出さない
- 配信:notify-relay への依頼はアウトボックス経由。重複防止キーを必ず付ける(ADR-0002)
- APIの入出力は FormRequest と Resource で定義し、OpenAPI(openapi/)と一致させる

## テスト

- 受け入れ基準ごとに Feature テストを書き、テスト名に受け入れ基準のIDを入れる(例:`test_AC_002_07_expired_link_is_rejected`)
- テナント分離のテストは tests/Tenancy に置く
- DBを使うテストは MySQL で動かす(CIと同じ)
