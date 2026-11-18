
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

// フォーム送信時
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRFトークンの確認
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $message = "不正なリクエストです。";

    } else {

        $current_password = $_POST["current_password"] ?? "";
        $new_password = $_POST["new_password"] ?? "";
        $password_confirm = $_POST["password_confirm"] ?? "";

        if (
            $current_password === "" ||
            $new_password === "" ||
            $password_confirm === ""
        ) {

            $message = "すべての項目を入力してください。";

        } elseif (
            strlen($current_password) > 1024 ||
            strlen($new_password) > 1024 ||
            strlen($password_confirm) > 1024
        ) {

            $message = "パスワードが長すぎます。";

        } elseif (mb_strlen($new_password) < 8) {

            $message = "新しいパスワードは８文字以上にしてください。";

        } elseif ($new_password !== $password_confirm) {

            $message = "新しいパスワードが一致しません。";

        } else {

            // 現在のパスワードを取得
            $sql = "SELECT password FROM users WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ":id" => $_SESSION["user_id"]
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 現在のパスワードを確認
            if (
                !$user ||
                !password_verify($current_password, $user["password"])
            ) {

                $message = "現在のパスワードが正しくありません。";

            } elseif (password_verify($new_password, $user["password"])) {

                $message = "現在と同じパスワードは使用できません。";

            } else {

                try {

                    // 新しいパスワードをハッシュ化
                    $hashed_password = password_hash(
                        $new_password,
                        PASSWORD_DEFAULT
                    );

                    // パスワードを更新
                    $sql = "UPDATE users
                            SET password = :password
                            WHERE id = :id";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ":password" => $hashed_password,
                        ":id" => $_SESSION["user_id"]
                    ]);

                    $message = "パスワードを変更しました！";

                    // CSRFトークンを再発行
                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                } catch (PDOException $e) {

                    error_log($e->getMessage());

                    $message = "パスワードの変更に失敗しました。";
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
    <title>パスワード変更</title>
    <link rel="stylesheet" href="./style/change_password.css">
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


    <!-- パスワード変更画面 -->
    <main class="change_password">

        <div class="page_heading">
            <p class="page_subtitle">ACCOUNT SETTINGS</p>
            <h1>パスワード変更</h1>
            <p class="page_description">
                アカウントのパスワードを変更できます。
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
                <label for="current_password">現在のパスワード</label>
                <input
                    type="password"
                    id="current_password"
                    name="current_password"
                    required
                >
            </div>

            <div class="form_group">
                <label for="new_password">新しいパスワード</label>
                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    required
                >
                <p class="input_description">
                    8文字以上で入力してください。
                </p>
            </div>

            <div class="form_group">
                <label for="password_confirm">新しいパスワード（確認）</label>
                <input
                    type="password"
                    id="password_confirm"
                    name="password_confirm"
                    required
                >
            </div>

            <button type="submit">パスワードを変更する</button>

        </form>


        <div class="page_footer">
            <a href="account_settings.php">アカウント設定に戻る</a>
        </div>

    </main>

</body>
</html>
