
<?php

// HTTPS接続かどうかを確認
$is_https = (
    isset($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] !== "off"
);

// セッションIDの不正利用を防止
ini_set("session.use_strict_mode", "1");

// CookieだけでセッションIDを受け渡す
ini_set("session.use_only_cookies", "1");

// URLへのセッションID付与を無効化
ini_set("session.use_trans_sid", "0");

// セッションCookieの設定
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "secure" => $is_https,
    "httponly" => true,
    "samesite" => "Lax"
]);

// セッション開始
session_start();

// CSRFトークンが存在しない場合は発行
if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

?>
