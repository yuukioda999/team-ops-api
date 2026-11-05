# 3リポジトリの初期構成案

- ステータス: 提案中
- 日付: 2026-11-05

## 全体方針

- **面接官は1回のcloneで全体を起動できる**:team-ops-api に docker compose を置き、notify-relay と team-ops-web はGitHub Container Registryに公開したイメージを取得して起動する。`git clone` → `make up` の2コマンドで、3サービスとデモデータがそろう
- **システム全体のドキュメントは team-ops-api に置く**:要件定義・全体のADR・運用ドキュメントは「唯一の窓口」であるAPIのリポジトリに集約する。notify-relay はOSSとして単独で読めるよう、自分のADRとOpenAPIを持つ
- **仕様駆動開発は GitHub Spec Kit の流れに合わせる**:機能ごとに `specs/NNN-機能名/` を作り、spec(何を作るか)→ plan(どう作るか)→ tasks(作業分解)→ 実装の順に進める。導入時に最新の手順を確認して確定する
- **バージョン**:Laravel 13(PHP 8.4)、Go 1.26、React+TypeScript+Vite(着手時点の最新安定版)

---

## 1. team-ops-api(Laravel)

```
team-ops-api/
├── CLAUDE.md
├── .claude/
│   ├── settings.json          # 許可するコマンド、Hooksの設定
│   ├── agents/                # サブエージェント(後述)
│   └── skills/                # ADR作成・仕様作成などの手順
├── .specify/                  # Spec Kit の設定と、プロジェクト原則(constitution)
├── app/
│   ├── Http/                  # Controller、FormRequest、Resource(入出力だけ)
│   ├── UseCases/              # 業務処理。1ユースケース1クラス
│   ├── Models/                # Eloquentモデル
│   ├── Policies/              # 役割ごとの権限
│   ├── Support/Tenancy/       # チームIDの絞り込みを全クエリに強制する仕組み
│   ├── Support/Audit/         # 監査ログ
│   └── Infrastructure/NotifyRelay/  # 配信APIのクライアント、アウトボックス送信
├── database/                  # マイグレーション、デモデータ
├── tests/
│   ├── Unit/
│   ├── Feature/
│   ├── Tenancy/               # 他チームのデータが見えないことを全APIで検証
│   └── Architecture/          # 層の依存ルール(Controllerから直接DBを触らない等)
├── openapi/                   # このAPIのOpenAPI(フロント向け)
├── specs/                     # 機能ごとの spec / plan / tasks
├── docs/
│   ├── requirements/          # 要件定義、ヒアリング記録
│   ├── adr/                   # システム全体のADR
│   ├── design/                # ER図、シーケンス図、本書
│   ├── ops/                   # SLO、監視・アラート設計、手順書
│   ├── postmortems/           # 障害訓練の振り返り
│   └── ai-log/                # AIへの指示と、出力をどう直したか
├── loadtest/                  # k6のシナリオと結果
├── infra/terraform/           # AWS ECS構成(planまで)
├── compose.yaml               # 3サービス+MySQL+Mailpitを一発起動
└── .github/workflows/
```

## 2. notify-relay(Go)

```
notify-relay/
├── CLAUDE.md
├── .claude/
├── .specify/
├── api/openapi.yaml           # 配信APIとwebhookの仕様(正本)
├── cmd/notify-relay/          # 起動処理
├── internal/
│   ├── domain/                # 配信依頼・宛先・配信結果などの型とルール
│   ├── usecase/               # 受付、送信、再送、結果通知
│   ├── adapter/
│   │   ├── http/              # 配信APIの受け口、署名検証
│   │   ├── mailer/            # SMTPでのメール送信
│   │   └── webhook/           # Laravelへの結果通知
│   └── infra/                 # DB、流量制御、トレース
├── specs/
├── docs/adr/                  # notify-relay固有のADR
├── loadtest/
├── Dockerfile                 # 非rootで動かす
└── .github/workflows/
```

データの置き場所:同じMySQLサーバーに notify-relay 専用のデータベースを作る。Laravelのデータベースとは分け、接続ユーザーも別にする(GoはLaravelのDBを読まない、というルールを権限で強制する)。費用を増やさないための判断で、ADR-0004として記録する。

## 3. team-ops-web(React)

```
team-ops-web/
├── CLAUDE.md
├── .claude/
├── .specify/
├── src/
│   ├── features/              # 機能単位(team / invite / roster / notice ...)
│   ├── api/                   # OpenAPIから自動生成したクライアント
│   └── components/            # 共通UI
├── e2e/                       # Playwrightの画面テスト
├── specs/
└── .github/workflows/
```

APIとの型は、team-ops-api のOpenAPIから自動生成し、手書きしない。

---

## ローカル環境(team-ops-api の compose.yaml)

デモ環境でできることは、すべてローカルでも再現できるようにする。

| サービス | 役割 | ブラウザで開くURL |
|---|---|---|
| web | React SPA | http://localhost:5173 |
| api | Laravel API | http://localhost:8000 |
| api-worker | アウトボックス送信、定期処理 | — |
| notify-relay | Go配信サービス | — |
| mysql | team_ops / notify_relay の2スキーマ | — |
| mailpit | 送信メールの受け止め(外部には送らない) | http://localhost:8025 |
| otel-lgtm | Grafana一式(トレース・メトリクス・ログ) | http://localhost:3000 |

主なコマンド(Makefile):

| コマンド | 内容 |
|---|---|
| `make up` | 全サービスを起動し、デモデータを投入 |
| `make demo-reset` | すべてのデモチームを削除し、見本チームを作り直す(デモ環境の毎晩の掃除と同じ処理) |
| `make test` | 3リポジトリのテストを実行 |
| `make loadtest` | k6で負荷試験を実行し、結果を loadtest/results/ に保存 |
| `make chaos-smtp` | MailpitにSMTPエラーを一定の確率で返させる(再送の確認、障害訓練) |
| `make chaos-relay-down` | notify-relay を停止し、アウトボックスに溜まって復旧後に送られることを確認 |

- ログインのマジックリンクも Mailpit に届くので、ローカルだけでログインまで完結する
- Mailpit の障害注入機能(Chaos)で、SMTPの一時エラーや拒否を起こせる。障害訓練とポストモーテムはローカルでも実施できる
- 1通の通知を、ローカルのGrafanaでトレースIDから配信まで追跡できる

---

## CLAUDE.md に書く内容(3リポジトリ共通の骨子)

1. このリポジトリの目的と、他の2つとの関係
2. よく使うコマンド(起動、テスト、静的解析、OpenAPIの検証)
3. 守るべき設計ルール
   - API:全クエリにチームIDの絞り込み。個人情報をログに出さない。業務処理はUseCaseに書く
   - Go:業務ロジックを持たない。すべての配信依頼に重複防止キー
   - Web:Goを直接呼ばない。APIの型は生成物を使う
4. 作業の進め方:仕様(spec)→計画(plan)→作業分解(tasks)→実装。設計判断が出たらADRを書いてから実装する。迷ったら推測せず確認する
5. テストのルール:受け入れ基準ごとにテストを書き、テスト名に受け入れ基準のIDを入れる
6. やってはいけないこと:秘密情報のコミット、テストを消して通すこと、マイグレーションの書き換え

## .claude/ に置くもの

| 種類 | 名前 | 役割 |
|---|---|---|
| サブエージェント | reviewer | 設計ルール・ADRとの整合を見るコードレビュー役 |
| サブエージェント | test-writer | 受け入れ基準からテストを書く役 |
| サブエージェント | security-reviewer | 認可漏れ・テナント越え・個人情報の扱いを見る役 |
| スキル | adr | ADRの雛形と書き方 |
| スキル | ai-log | AIへの指示と修正内容を docs/ai-log に記録する手順 |
| Hooks | 編集後 | 整形(Pint / gofmt / Prettier)と静的解析を自動実行 |
| Hooks | 作業終了時 | 関連テストを実行し、失敗したら終了させない |

## CI(GitHub Actions)

| ジョブ | API | Go | Web |
|---|---|---|---|
| 静的解析 | PHPStan、Pint | golangci-lint | ESLint、TypeScript |
| テスト | PHPUnit(MySQL付き)、テナント分離テスト | go test(race検出付き) | Vitest、Playwright |
| OpenAPI | 構文検証、破壊的変更の検出 | 同左 | 生成クライアントの差分確認 |
| 脆弱性 | composer audit | govulncheck | npm audit |
| AIレビュー | PRにClaudeがレビューコメント | 同左 | 同左 |
| イメージ公開 | mainへのマージでGHCRに公開 | 同左 | 同左 |
| 依存更新 | Dependabot | 同左 | 同左 |

受け入れ基準の網羅状況:テスト名に含まれる受け入れ基準のIDを集計し、「どの受け入れ基準がテスト済みか」をPRに表示する(第1弾の途中で追加)。
