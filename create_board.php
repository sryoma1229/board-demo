
<?php
require_once __DIR__ . "/config/session.php";

require_once "config/db.php";

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

// 選択できるジャンル
$categories = ["雑談", "ニュース", "ゲーム", "アニメ", "マンガ", "音楽", "その他"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRFトークンの確認
    if (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        http_response_code(403);
        exit("不正なリクエストです。");

    }

    // 入力値の取得
    $title = $_POST["title"] ?? "";
    $description = $_POST["description"] ?? "";
    $category = $_POST["category"] ?? "";

    // 入力値が文字列か確認
    if (
        !is_string($title) ||
        !is_string($description) ||
        !is_string($category)
    ) {

        $message = "入力内容が正しくありません。";

    } else {

        $title = trim($title);
        $description = trim($description);

        $user_id = $_SESSION["user_id"];

        if ($title === "") {

            $message = "掲示板のタイトルを入力してください。";

        } elseif (mb_strlen($title) > 100) {

            $message = "タイトルは100文字以内にしてください。";

        } elseif (mb_strlen($description) > 500) {

            $message = "説明は500文字以内にしてください。";

        } elseif (!in_array($category, $categories, true)) {

            $message = "正しいジャンルを選択してください。";

        } else {

            try {

                $sql = "INSERT INTO boards (user_id, title, description, category)
                        VALUES (:user_id, :title, :description, :category)";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    ":user_id" => $user_id,
                    ":title" => $title,
                    ":description" => $description,
                    ":category" => $category
                ]);

                // 作成した掲示板のIDを取得
                $board_id = $pdo->lastInsertId();

                // 作成した掲示板のページへ移動
                header("Location: board.php?id=" . $board_id);
                exit;

            } catch (PDOException $e) {

                error_log($e->getMessage());

                $message = "掲示板の作成に失敗しました。";

            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>掲示板作成</title>
    <link rel="stylesheet" href="./style/create_board.css">
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


    <!-- 掲示板作成画面 -->
    <div class="create_board_page">

        <h3>掲示板を作成</h3>

        <p><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></p>


        <!-- 掲示板作成フォーム -->
        <form method="POST">

            <input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

            <div class="form_group">
                <label>掲示板のタイトル</label>
                <input type="text" name="title" maxlength="100" required>
            </div>


            <div class="form_group">
                <label>掲示板の説明</label>
                <textarea name="description" maxlength="500"></textarea>
            </div>


            <div class="form_group">
                <label>ジャンル</label>

                <select name="category" required>

                    <?php foreach ($categories as $category): ?>

                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, "UTF-8") ?>">
                            <?= htmlspecialchars($category, ENT_QUOTES, "UTF-8") ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>


            <button type="submit">掲示板を作成</button>

        </form>


        <!-- 掲示板一覧へ戻る -->
        <a href="index.php" class="back_button">
            掲示板一覧に戻る
        </a>

    </div>

</body>
</html>
