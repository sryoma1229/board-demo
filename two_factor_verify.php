
<?php

require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/vendor/autoload.php";

use PragmaRX\Google2FA\Google2FA;

$message = "";

// 仮認証状態か確認
if (
    !isset(
        $_SESSION["pending_2fa_user_id"],
        $_SESSION["pending_2fa_started_at"]
    ) ||
    !is_numeric($_SESSION["pending_2fa_user_id"]) ||
    !is_numeric($_SESSION["pending_2fa_started_at"])
) {
    header("Location: login.php");
    exit;
}

$pending_user_id = (int)$_SESSION["pending_2fa_user_id"];
$started_at = (int)$_SESSION["pending_2fa_started_at"];

// 仮認証の有効期限は5分
if (
    $pending_user_id <= 0 ||
    time() < $started_at ||
    time() - $started_at > 300
) {
    unset(
        $_SESSION["pending_2fa_user_id"],
        $_SESSION["pending_2fa_started_at"],
        $_SESSION["two_factor_attempts"]
    );

    session_regenerate_id(true);

    header("Location: login.php");
    exit;
}

// 認証失敗回数を初期化
if (
    !isset($_SESSION["two_factor_attempts"]) ||
    !is_numeric($_SESSION["two_factor_attempts"])
) {
    $_SESSION["two_factor_attempts"] = 0;
}

// ユーザー情報を取得
try {

    $sql = "
        SELECT
            id,
            username,
            two_factor_enabled,
            two_factor_secret
        FROM users
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":id" => $pending_user_id
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ユーザーが存在しない、または2段階認証が無効
    if (
        !$user ||
        (int)$user["two_factor_enabled"] !== 1 ||
        empty($user["two_factor_secret"])
    ) {
        unset(
            $_SESSION["pending_2fa_user_id"],
            $_SESSION["pending_2fa_started_at"],
            $_SESSION["two_factor_attempts"]
        );

        session_regenerate_id(true);

        header("Location: login.php");
        exit;
    }

    // POST処理
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        // CSRFトークン確認
        $csrf_token = $_POST["csrf_token"] ?? "";

        if (
            !is_string($csrf_token) ||
            !isset($_SESSION["csrf_token"]) ||
            !hash_equals($_SESSION["csrf_token"], $csrf_token)
        ) {
            http_response_code(403);
            exit("不正なリクエストです。");
        }

        // 認証コード取得
        $code = $_POST["code"] ?? "";

        // 認証コードの形式を確認
        $is_valid = false;

        if (
            is_string($code) &&
            preg_match('/^\d{6}$/', $code)
        ) {
            $google2fa = new Google2FA();

            // 認証コードを検証
            $is_valid = $google2fa->verifyKey(
                $user["two_factor_secret"],
                $code,
                1
            );
        }

        // 認証成功
        if ($is_valid) {

            // セッションIDを再生成
            session_regenerate_id(true);

            // 通常ログイン状態にする
            $_SESSION["user_id"] = (int)$user["id"];
            $_SESSION["username"] = $user["username"];

            // 仮認証情報を削除
            unset(
                $_SESSION["pending_2fa_user_id"],
                $_SESSION["pending_2fa_started_at"],
                $_SESSION["two_factor_attempts"]
            );

            // CSRFトークンを再生成
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            // ログイン完了
            header("Location: index.php");
            exit;

        } else {

            // 認証失敗回数を増やす
            $_SESSION["two_factor_attempts"]++;

            // 5回失敗した場合
            if ($_SESSION["two_factor_attempts"] >= 5) {

                // 仮認証状態を終了
                unset(
                    $_SESSION["pending_2fa_user_id"],
                    $_SESSION["pending_2fa_started_at"],
                    $_SESSION["two_factor_attempts"]
                );

                session_regenerate_id(true);

                header("Location: login.php");
                exit;
            }

            $remaining = 5 - $_SESSION["two_factor_attempts"];

            if (
                !is_string($code) ||
                !preg_match('/^\d{6}$/', $code)
            ) {
                $message = "6桁の認証コードを入力してください。あと{$remaining}回入力できます。";
            } else {
                $message = "認証コードが正しくありません。あと{$remaining}回入力できます。";
            }
        }
    }

} catch (Throwable $e) {

    error_log($e->getMessage());

    $message = "認証処理に失敗しました。";

}

?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>2段階認証</title>
    <link rel="stylesheet" href="./style/two_factor_verify.css">
</head>

<body>

    <!-- ヘッダー -->
    <header>
        <div class="header_item1">
            <a href="index.php">
                <img src="./img/7ch_logo_header.svg" alt="ロゴ">
            </a>
        </div>
    </header>


    <!-- 認証画面 -->
    <main class="verify">

        <h2>2段階認証</h2>

        <p>
            Google Authenticatorに表示されている
            6桁の認証コードを入力してください。
        </p>

        <p class="message">
            <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
        </p>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
            >

            <div class="form_group">

                <label>認証コード</label>

                <input
                    type="text"
                    name="code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="123456"
                    autocomplete="one-time-code"
                    required
                >

            </div>

            <button type="submit">
                認証する
            </button>

        </form>

        <p class="notice">
            認証コードを確認できない場合は、最初からログインし直してください。
        </p>

    </main>

</body>
</html>
