
<?php

require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";

// ログイン確認
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$message = "";

// CSRFトークンの発行
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// 現在のユーザー情報を取得
$sql = "SELECT username FROM users WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ":id" => $_SESSION["user_id"]
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$current_username = $user["username"];
$new_username = "";

// フォーム送信時
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRFトークンの確認
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $message = "不正なリクエストです。";

    } else {

        $new_username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($new_username === "" || $password === "") {

            $message = "すべての項目を入力してください。";

        } elseif (mb_strlen($new_username) > 50) {

            $message = "ユーザー名は５０文字以内にしてください。";

        } elseif (preg_match('/[\x00-\x1F\x7F]/u', $new_username)) {

            $message = "ユーザー名に使用できない文字が含まれています。";

        } elseif (strlen($password) > 1024) {

            $message = "パスワードが長すぎます。";

        } else {

            // 現在のパスワードを取得
            $sql = "SELECT password FROM users WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ":id" => $_SESSION["user_id"]
            ]);

            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            // パスワードの確認
            if (!$account || !password_verify($password, $account["password"])) {

                $message = "現在のパスワードが正しくありません。";

            } elseif ($new_username === $current_username) {

                $message = "現在と同じユーザー名です。";

            } else {

                try {

                    // ユーザー名の重複確認
                    $sql = "SELECT id FROM users
                            WHERE username = :username
                            AND id != :id";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ":username" => $new_username,
                        ":id" => $_SESSION["user_id"]
                    ]);

                    if ($stmt->fetch()) {

                        $message = "このユーザー名は既に使用されています。";

                    } else {

                        // ユーザー名を更新
                        $sql = "UPDATE users
                                SET username = :username
                                WHERE id = :id";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":username" => $new_username,
                            ":id" => $_SESSION["user_id"]
                        ]);

                        // セッション内のユーザー名も更新
                        $_SESSION["username"] = $new_username;

                        $current_username = $new_username;
                        $new_username = "";

                        $message = "ユーザー名を変更しました！";

                        // CSRFトークンを再発行
                        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                    }

                } catch (PDOException $e) {

                    if ($e->getCode() === "23000") {

                        $message = "このユーザー名は既に使用されています。";

                    } else {

                        error_log($e->getMessage());

                        $message = "ユーザー名の変更に失敗しました。";
                    }
                }
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>ユーザー名変更</title>
    <link rel="stylesheet" href="./style/change_username.css">
</head>

<body>

    <!-- ヘッダー -->
    <header>
        <div class="header_item1">
            <a href="index.php">
                <img src="./img/7ch_logo_header.svg" alt="7ch">
            </a>
        </div>

        <div class="header_item2">
            <a href="mypage.php">マイページ</a>
            <a href="account_settings.php">アカウント設定</a>
        </div>
    </header>


    <!-- ユーザー名変更画面 -->
    <main class="change_username">

        <div class="page_heading">
            <p class="page_subtitle">ACCOUNT SETTINGS</p>
            <h1>ユーザー名変更</h1>
            <p class="page_description">
                新しいユーザー名を入力してください。
            </p>
        </div>


        <!-- 現在のユーザー名 -->
        <div class="current_account">
            <p class="current_label">現在のユーザー名</p>
            <p class="current_name">
                <?= htmlspecialchars($current_username, ENT_QUOTES, "UTF-8") ?>
            </p>
        </div>


        <!-- メッセージ -->
        <?php if ($message !== ""): ?>
            <p class="message">
                <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
            </p>
        <?php endif; ?>


        <!-- 変更フォーム -->
        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

            <div class="form_group">
                <label for="username">新しいユーザー名</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="50"
                    value="<?= htmlspecialchars($new_username, ENT_QUOTES, "UTF-8") ?>"
                    required
                >
                <p class="input_description">50文字以内で入力してください。</p>
            </div>

            <div class="form_group">
                <label for="password">現在のパスワード</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >
                <p class="input_description">
                    本人確認のため、現在のパスワードを入力してください。
                </p>
            </div>

            <button type="submit">ユーザー名を変更する</button>

        </form>


        <div class="page_footer">
            <a href="account_settings.php">アカウント設定に戻る</a>
        </div>

    </main>

</body>
</html>
