
<?php

require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/vendor/autoload.php";

use PragmaRX\Google2FA\Google2FA;
use chillerlan\QRCode\QRCode;

// ログイン確認
if (
    !isset($_SESSION["user_id"]) ||
    !is_numeric($_SESSION["user_id"])
) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];

$message = "";
$user = false;

// 認証失敗回数・ロック時間を管理するセッションキー
$setup_attempt_key = "two_factor_setup_attempts_" . $user_id;
$setup_lock_key = "two_factor_setup_lock_until_" . $user_id;

$disable_attempt_key = "two_factor_disable_attempts_" . $user_id;
$disable_lock_key = "two_factor_disable_lock_until_" . $user_id;

// Google2FAを初期化
$google2fa = new Google2FA();

// ユーザー情報を取得する関数
function getTwoFactorUser(PDO $pdo, int $user_id): array|false
{
    $sql = "
        SELECT
            id,
            username,
            password,
            two_factor_enabled,
            two_factor_secret,
            two_factor_confirmed_at
        FROM users
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":id" => $user_id
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {

    $user = getTwoFactorUser($pdo, $user_id);

    if (!$user) {

        session_destroy();

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
            !is_string($_SESSION["csrf_token"]) ||
            !hash_equals($_SESSION["csrf_token"], $csrf_token)
        ) {

            http_response_code(403);
            exit("不正なリクエストです。");

        }

        $action = $_POST["action"] ?? "";

        if (!is_string($action)) {
            $action = "";
        }

        // ① 2段階認証の設定開始
        if ($action === "start") {

            if ((int)$user["two_factor_enabled"] === 1) {

                $message = "2段階認証はすでに有効です。";

            } else {

                // 新しい秘密鍵を生成
                $secret = $google2fa->generateSecretKey();

                // 秘密鍵を仮保存
                $sql = "
                    UPDATE users
                    SET
                        two_factor_secret = :secret,
                        two_factor_confirmed_at = NULL
                    WHERE id = :id
                    AND two_factor_enabled = 0
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ":secret" => $secret,
                    ":id" => $user_id
                ]);

                if ($stmt->rowCount() === 1) {

                    // 設定開始時に認証失敗回数を初期化
                    unset(
                        $_SESSION[$setup_attempt_key]
                    );

                    header("Location: two_factor_settings.php");
                    exit;

                } else {

                    $message = "設定を開始できませんでした。もう一度お試しください。";

                }

            }

        // ② 6桁コードを確認して有効化
        } elseif ($action === "confirm") {

            $code = $_POST["code"] ?? "";

            if ((int)$user["two_factor_enabled"] === 1) {

                $message = "2段階認証はすでに有効です。";

            } else {

                // ロック時間を確認
                $lock_until = (int)($_SESSION[$setup_lock_key] ?? 0);

                if ($lock_until > time()) {

                    $remaining = $lock_until - time();
                    $minutes = (int)ceil($remaining / 60);

                    $message = "認証コードの入力回数が上限に達しました。約{$minutes}分後に再度お試しください。";

                } else {

                    // ロック時間が過ぎていれば初期化
                    unset($_SESSION[$setup_lock_key]);

                    if (!is_string($code) || !preg_match('/^\d{6}$/', $code)) {

                        $is_valid = false;

                    } elseif (empty($user["two_factor_secret"])) {

                        $is_valid = false;

                    } else {

                        // 認証コードを検証
                        $is_valid = $google2fa->verifyKey(
                            $user["two_factor_secret"],
                            $code,
                            1
                        );

                    }

                    if (!$is_valid) {

                        // 認証失敗回数を増やす
                        $_SESSION[$setup_attempt_key] =
                            (int)($_SESSION[$setup_attempt_key] ?? 0) + 1;

                        if ($_SESSION[$setup_attempt_key] >= 5) {

                            // 5回失敗したら15分間停止
                            $_SESSION[$setup_lock_key] = time() + 900;

                            $message = "認証コードの入力回数が上限に達しました。15分後に再度お試しください。";

                        } elseif (
                            !is_string($code) ||
                            !preg_match('/^\d{6}$/', $code)
                        ) {

                            $remaining = 5 - $_SESSION[$setup_attempt_key];

                            $message = "6桁の認証コードを入力してください。あと{$remaining}回入力できます。";

                        } else {

                            $remaining = 5 - $_SESSION[$setup_attempt_key];

                            $message = "認証コードが正しくありません。あと{$remaining}回入力できます。";

                        }

                    } else {

                        // 認証成功後に有効化
                        $sql = "
                            UPDATE users
                            SET
                                two_factor_enabled = 1,
                                two_factor_confirmed_at = NOW()
                            WHERE id = :id
                            AND two_factor_enabled = 0
                            AND two_factor_secret = :secret
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":id" => $user_id,
                            ":secret" => $user["two_factor_secret"]
                        ]);

                        if ($stmt->rowCount() === 1) {

                            // 認証失敗情報を削除
                            unset(
                                $_SESSION[$setup_attempt_key],
                                $_SESSION[$setup_lock_key]
                            );

                            $message = "2段階認証を有効にしました。";

                        } else {

                            $message = "設定を更新できませんでした。もう一度お試しください。";

                        }

                    }

                }

            }

        // ③ 2段階認証の無効化
        } elseif ($action === "disable") {

            $password = $_POST["password"] ?? "";

            if ((int)$user["two_factor_enabled"] !== 1) {

                $message = "2段階認証は有効になっていません。";

            } else {

                // ロック時間を確認
                $lock_until = (int)($_SESSION[$disable_lock_key] ?? 0);

                if ($lock_until > time()) {

                    $remaining = $lock_until - time();
                    $minutes = (int)ceil($remaining / 60);

                    $message = "パスワードの入力回数が上限に達しました。約{$minutes}分後に再度お試しください。";

                } else {

                    // ロック時間が過ぎていれば初期化
                    unset($_SESSION[$disable_lock_key]);

                    if (
                        !is_string($password) ||
                        !password_verify($password, $user["password"])
                    ) {

                        // パスワード失敗回数を増やす
                        $_SESSION[$disable_attempt_key] =
                            (int)($_SESSION[$disable_attempt_key] ?? 0) + 1;

                        if ($_SESSION[$disable_attempt_key] >= 5) {

                            // 5回失敗したら15分間停止
                            $_SESSION[$disable_lock_key] = time() + 900;

                            $message = "パスワードの入力回数が上限に達しました。15分後に再度お試しください。";

                        } else {

                            $remaining = 5 - $_SESSION[$disable_attempt_key];

                            $message = "パスワードが正しくありません。あと{$remaining}回入力できます。";

                        }

                    } else {

                        // 秘密鍵も削除して無効化
                        $sql = "
                            UPDATE users
                            SET
                                two_factor_enabled = 0,
                                two_factor_secret = NULL,
                                two_factor_confirmed_at = NULL
                            WHERE id = :id
                            AND two_factor_enabled = 1
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":id" => $user_id
                        ]);

                        if ($stmt->rowCount() === 1) {

                            // 認証失敗情報を削除
                            unset(
                                $_SESSION[$disable_attempt_key],
                                $_SESSION[$disable_lock_key],
                                $_SESSION[$setup_attempt_key],
                                $_SESSION[$setup_lock_key]
                            );

                            $message = "2段階認証を無効にしました。";

                        } else {

                            $message = "設定を更新できませんでした。もう一度お試しください。";

                        }

                    }

                }

            }

        } else {

            $message = "不正な操作です。";

        }

        // 更新後のユーザー情報を再取得
        $user = getTwoFactorUser($pdo, $user_id);

    }

} catch (Throwable $e) {

    error_log($e->getMessage());

    $message = "処理に失敗しました。";

}

// ユーザー情報が取得できなかった場合
if (!$user) {

    http_response_code(500);
    exit("ユーザー情報を取得できませんでした。");

}


// QRコードを生成
$qrCodeDataUri = "";

if (
    (int)$user["two_factor_enabled"] === 0 &&
    !empty($user["two_factor_secret"])
) {

    try {

        // Google Authenticator用の登録URL
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            "7ch掲示板",
            $user["username"],
            $user["two_factor_secret"]
        );

        // QRコードを生成
        $qrCodeDataUri = (new QRCode())->render($qrCodeUrl);

    } catch (Throwable $e) {

        error_log($e->getMessage());

        $message = "QRコードを生成できませんでした。";

    }

}

?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>2段階認証設定</title>

    <link rel="stylesheet" href="./style/two_factor_settings.css">
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


    <!-- 2段階認証設定画面 -->
    <main class="settings">

        <h2>2段階認証設定</h2>

        <p class="message">
            <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
        </p>


        <?php if ((int)$user["two_factor_enabled"] === 1): ?>

            <!-- 有効化済み -->
            <section class="status">

                <h3>2段階認証は有効です</h3>

                <p>
                    現在、このアカウントでは2段階認証が有効になっています。
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="disable"
                    >

                    <div class="form_group">

                        <label>現在のパスワード</label>

                        <input
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >

                    </div>

                    <button type="submit" class="danger_button">
                        2段階認証を無効にする
                    </button>

                </form>

            </section>


        <?php elseif (!empty($user["two_factor_secret"])): ?>

            <!-- 設定途中 -->
            <section class="setup">

                <h3>Google Authenticatorの設定</h3>

                <p>
                    スマートフォンのGoogle Authenticatorを開き、
                    以下のQRコードを読み取ってください。
                </p>

                <?php if ($qrCodeDataUri !== ""): ?>

                    <div class="qr_code">

                        <img
                            src="<?= htmlspecialchars($qrCodeDataUri, ENT_QUOTES, "UTF-8") ?>"
                            alt="認証用QRコード"
                        >

                    </div>

                <?php endif; ?>

                <p>
                    アプリに表示された6桁の認証コードを入力してください。
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="confirm"
                    >

                    <div class="form_group">

                        <label>6桁の認証コード</label>

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
                        認証して有効化する
                    </button>

                </form>

            </section>


        <?php else: ?>

            <!-- 未設定 -->
            <section class="setup">

                <h3>2段階認証は無効です</h3>

                <p>
                    2段階認証を有効にすると、ログイン時にパスワードに加えて、
                    スマートフォンに表示される認証コードが必要になります。
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="start"
                    >

                    <button type="submit">
                        2段階認証を設定する
                    </button>

                </form>

            </section>

        <?php endif; ?>


        <a href="account_settings.php" class="back_button">
            アカウント設定に戻る
        </a>

    </main>

</body>
</html>
