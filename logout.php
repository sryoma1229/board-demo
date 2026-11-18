
<?php

require_once __DIR__ . "/config/session.php";

// POST以外のアクセスを拒否
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("不正なアクセスです。");
}

// CSRFトークンを確認
$csrf_token = $_POST["csrf_token"] ?? "";

if (
    !is_string($csrf_token) ||
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $csrf_token)
) {
    http_response_code(403);
    exit("不正なリクエストです。");
}

// セッション情報を削除
$_SESSION = [];

// セッションCookieを削除
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        [
            "expires" => time() - 42000,
            "path" => $params["path"],
            "domain" => $params["domain"],
            "secure" => $params["secure"],
            "httponly" => $params["httponly"],
            "samesite" => $params["samesite"] ?? "Lax"
        ]
    );
}

// セッションを破棄
session_destroy();

// 掲示板一覧へ移動
header("Location: index.php");
exit;

?>
