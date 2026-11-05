<?php

return [

    /*
    | メールアドレスの照合用ハッシュ(ブラインドインデックス)の鍵。APP_KEY とは別にする(ADR-0003)。
    | base64: で始まる場合はデコードして使う。
    */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
    | デモモード。宛先を .invalid ドメインだけに制限する(notify-relay 側でも同じ制限をかける)。
    */
    'demo_mode' => (bool) env('DEMO_MODE', false),

    /*
    | Web 画面の URL。メールに書くリンク(ログイン、メール変更の確認)の先頭に使う。
    */
    'web_url' => rtrim((string) env('WEB_URL', 'http://localhost:5173'), '/'),

    /*
    | ログイン用・メール変更用のリンクの有効期間(分)と、1時間に要求できる回数(AC-001-03・05)。
    */
    'login_link' => [
        'ttl_minutes' => 15,
        'per_hour_per_email' => 5,
        'per_hour_per_ip' => 30,
    ],

    /*
    | notify-relay(メール配信)への接続(ADR-0002)。依頼には HMAC 署名を付ける。
    | secret は base64 で、notify-relay の RELAY_SIGNING_KEYS と同じ値にする。
    */
    'notify_relay' => [
        'url' => env('NOTIFY_RELAY_URL', 'http://notify-relay:8080'),
        'key_id' => env('NOTIFY_RELAY_KEY_ID'),
        'secret' => env('NOTIFY_RELAY_SECRET'),
        'timeout' => (int) env('NOTIFY_RELAY_TIMEOUT', 10),
    ],

];
