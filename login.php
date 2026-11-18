
<?php

require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

    // 入力値を取得
    $username = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";

    if (!is_string($username) || !is_string($password)) {

        $message = "正しい形式で入力してください。";

    } else {

        $username = trim($username);

        if ($username === "" || $password === "") {

            $message = "ユーザー名とパスワードを入力してください。";

        } elseif (mb_strlen($username) > 50) {

            $message = "ユーザー名またはパスワードが違います。";

        } else {

            try {

                // 現在のログイン試行回数と制限状態を確認
                $sql = "
                    SELECT
                        attempts,
                        locked_until,
                        (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
                    FROM login_attempts
                    WHERE username = :username
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ":username" => $username
                ]);

                $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

                // 15分の制限中か確認
                if ($attempt && $attempt["is_locked"]) {

                    $message = "ログインに5回失敗したため、15分間ログインできません。";

                } else {

                    // 制限時間が過ぎていたら記録を削除
                    if (
                        $attempt &&
                        $attempt["locked_until"] !== null
                    ) {

                        $sql = "
                            DELETE FROM login_attempts
                            WHERE username = :username
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":username" => $username
                        ]);
                    }

                    // ユーザー情報を取得
                    $sql = "
                        SELECT *
                        FROM users
                        WHERE username = :username
                    ";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ":username" => $username
                    ]);

                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && password_verify($password, $user["password"])) {

                        // パスワード認証成功時は失敗回数を削除
                        $sql = "
                            DELETE FROM login_attempts
                            WHERE username = :username
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":username" => $username
                        ]);

                        // セッションIDを再生成
                        session_regenerate_id(true);

                        // 古い認証情報や仮認証情報を削除
                        unset(
                            $_SESSION["user_id"],
                            $_SESSION["username"],
                            $_SESSION["pending_2fa_user_id"],
                            $_SESSION["pending_2fa_started_at"],
                            $_SESSION["two_factor_attempts"]
                        );

                        // 2段階認証が有効か確認
                        if ((int)($user["two_factor_enabled"] ?? 0) === 1) {

                            // 2段階認証待ちの状態にする
                            $_SESSION["pending_2fa_user_id"] = (int)$user["id"];
                            $_SESSION["pending_2fa_started_at"] = time();

                            // CSRFトークンを再生成
                            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                            // 2段階認証画面へ移動
                            header("Location: two_factor_verify.php");
                            exit;

                        } else {

                            // 2段階認証が無効なら通常ログイン
                            $_SESSION["user_id"] = (int)$user["id"];
                            $_SESSION["username"] = $user["username"];

                            // CSRFトークンを再生成
                            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                            header("Location: index.php");
                            exit;
                        }

                    } else {

                        // ログイン失敗時に回数を増やす
                        $sql = "
                            INSERT INTO login_attempts
                                (username, attempts)
                            VALUES
                                (:username, 1)
                            ON DUPLICATE KEY UPDATE
                                locked_until = CASE
                                    WHEN attempts >= 4
                                    THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                                    ELSE locked_until
                                END,
                                attempts = attempts + 1
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":username" => $username
                        ]);

                        // 更新後の失敗回数を取得
                        $sql = "
                            SELECT attempts
                            FROM login_attempts
                            WHERE username = :username
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":username" => $username
                        ]);

                        $failed_attempts = (int)$stmt->fetchColumn();

                        // 残り回数を表示
                        if ($failed_attempts >= 5) {

                            $message = "ログインに5回失敗したため、15分間ログインできません。";

                        } else {

                            $remaining = 5 - $failed_attempts;

                            $message = "ユーザー名またはパスワードが違います。あと{$remaining}回失敗すると15分間ログインできなくなります。";
                        }
                    }
                }

            } catch (PDOException $e) {

                error_log($e->getMessage());

                $message = "ログイン処理に失敗しました。";
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>ログイン</title>
    <link rel="stylesheet" href="./style/login.css">
</head>

<body>

    <!-- ヘッダー -->
    <header>
        <div class="header_item1">
            <a href="index.php">
                <img src="./img/7ch_logo_header.svg">
            </a>
        </div>
    </header>

    <!-- ログイン画面 -->
    <div class="login">

        <h3>ユーザーネームとパスワードを入れてログインしてください</h3>

        <p><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></p>

        <!-- ログインフォーム -->
        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
            >

            <div class="form_group">
                <label>ユーザー名</label>
                <input type="text" name="username" required>
            </div>

            <div class="form_group">
                <label>パスワード</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit">ログイン</button>

        </form>

        <!-- 新規会員登録 -->
        <a href="register.php" class="register_button">
            新規会員登録
        </a>

    </div>

</body>
</html>
