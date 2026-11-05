<?php

return [

    /*
    | メールアドレスの照合用ハッシュ(ブラインドインデックス)の鍵。APP_KEY とは別にする(ADR-0003)。
    | base64: で始まる場合はデコードして使う。
    */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

];
