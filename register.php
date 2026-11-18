
<?php

require_once __DIR__ . "/config/session.php";

require_once "config/db.php";

$message = "";

// CSRFトークンの発行
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRFトークンの確認
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $message = "不正なリクエストです。";

    } else {

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $password_confirm = $_POST["password_confirm"] ?? "";

        if ($username === "" || $password === "" || $password_confirm === "") {

            $message = "すべての項目を入力してください。";

        } elseif (mb_strlen($username) > 50) {

            $message = "ユーザー名は５０文字以内にしてください。";

        } elseif (preg_match('/[\x00-\x1F\x7F]/u', $username)) {

            $message = "ユーザー名に使用できない文字が含まれています。";

        } elseif (strlen($password) > 1024) {

            $message = "パスワードが長すぎます。";

        } elseif (mb_strlen($password) < 8) {

            $message = "パスワードは８文字以上にしてください。";

        } elseif ($password !== $password_confirm) {

            $message = "パスワードが一致しません。";

        } else {

            $hashedpassword = password_hash($password, PASSWORD_DEFAULT);

            try {

                $sql = "INSERT INTO users (username, password)
                        VALUES (:username, :password)";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    ":username" => $username,
                    ":password" => $hashedpassword
                ]);

                $message = "ユーザー登録に成功しました！";

                // 登録成功後にトークンを再発行
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            } catch (PDOException $e) {

                if ($e->getCode() === "23000") {

                    $message = "このユーザー名は既に使用されています。";

                } else {

                    error_log($e->getMessage());

                    $message = "登録に失敗しました。";

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
    <title>ユーザー登録画面</title>
    <link rel="stylesheet" href="./style/register.css">
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


    <!-- ユーザー登録画面 -->
    <div class="register">

        <h3>ユーザー登録</h3>

        <p><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></p>

        <!-- 登録フォーム -->
        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

            <div class="form_group">
                <label>ユーザー名</label>
                <input type="text" name="username" maxlength="50" required>
            </div>

            <div class="form_group">
                <label>パスワード</label>
                <input type="password" name="password" required>
            </div>

            <div class="form_group">
                <label>パスワード（確認）</label>
                <input type="password" name="password_confirm" required>
            </div>

            <button type="submit">登録</button>

        </form>


        <!-- ログイン画面へ戻る -->
        <a href="login.php" class="login_button">
            ログイン画面へ戻る
        </a>

    </div>

</body>
</html>
