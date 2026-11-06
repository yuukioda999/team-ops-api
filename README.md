# team-ops-api

少年スポーツチーム運営ツールのバックエンドAPI(Laravel)です。認証・権限・マルチテナント・業務ロジックを担当します。

> **現在の状況:開発中(2026年11月)。001 アカウントとチーム作成(ログイン用リンク、表示名、メールアドレスの変更、チームの作成と一覧)の API まで完成**
> システム全体の要件・設計判断・機能仕様と、ローカル環境の起動方法は [team-ops](https://github.com/yuukioda999/team-ops) にまとめています。

## 技術

- Laravel 13(PHP 8.4)、API専用
- 認証:Sanctum(SPA向けCookie認証)、メールのマジックリンク
- MySQL
- API仕様:OpenAPI

## ライセンス

[MIT](LICENSE)
