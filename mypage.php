
<?php
require_once __DIR__ . "/config/session.php";

require_once "config/db.php";

// ログインしていなければログイン画面へ
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["username"]) ||
    !is_numeric($_SESSION["user_id"]) ||
    !is_string($_SESSION["username"])
) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION["username"];
$user_id = (int)$_SESSION["user_id"];

try {

    // 自分が作成した掲示板を取得
    $sql = "SELECT *
            FROM boards
            WHERE user_id = :user_id
            ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $user_id]);
    $my_boards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 自分の投稿を取得（削除済み投稿は除外）
    $sql = "SELECT posts.*, boards.title AS board_title
            FROM posts
            INNER JOIN boards ON posts.board_id = boards.id
            WHERE posts.user_id = :user_id
            AND posts.is_deleted = 0
            ORDER BY posts.created_at DESC
            LIMIT 5";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $user_id]);
    $my_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 自分が作成した掲示板の数
    $board_count = count($my_boards);

    // 自分の投稿数
    $sql = "SELECT COUNT(*)
            FROM posts
            WHERE user_id = :user_id
            AND is_deleted = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $user_id]);
    $post_count = (int)$stmt->fetchColumn();

} catch (PDOException $e) {

    error_log($e->getMessage());

    http_response_code(500);
    exit("データの取得に失敗しました。");

}

?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>マイページ</title>

    <link rel="stylesheet" href="./style/mypage.css">
</head>

<body>

    <!-- ヘッダー -->
    <header>

        <div class="header_item1">
            <a href="index.php">
                <img src="./img/7ch_logo_header.svg" alt="ロゴ">
            </a>
        </div>

        <div class="header_item2">
            <a href="index.php">掲示板一覧</a>
        </div>

    </header>

    <main class="mypage">

        <!-- ユーザー情報 -->
        <section class="user_info">

            <div class="user_info_heading">

                <h1>マイページ</h1>

                <a href="account_settings.php" class="security_button">
                    <span class="settings_icon">⚙</span>
                    <span>アカウント設定</span>
                    <span class="settings_arrow">›</span>
                </a>

            </div>

            <p class="welcome">
                ようこそ、
                <strong><?= htmlspecialchars($username, ENT_QUOTES, "UTF-8") ?></strong>
                さん！
            </p>

            <div class="user_detail">

                <p>
                    ユーザーID：
                    <?= htmlspecialchars((string)$user_id, ENT_QUOTES, "UTF-8") ?>
                </p>

                <p>ログイン中です。</p>

            </div>

            <!-- 利用状況 -->
            <div class="user_counts">

                <div class="count_item">
                    <span class="count_number"><?= $board_count ?></span>
                    <span class="count_label">作成した掲示板</span>
                </div>

                <div class="count_item">
                    <span class="count_number"><?= $post_count ?></span>
                    <span class="count_label">投稿数</span>
                </div>

            </div>

        </section>

        <!-- 作成した掲示板 -->
        <section class="mypage_section">

            <h2>自分が作成した掲示板</h2>

            <?php if (count($my_boards) > 0): ?>

                <div class="board_list">

                    <?php foreach ($my_boards as $board): ?>

                        <a href="board.php?id=<?= (int)$board["id"] ?>" class="board_item">

                            <div class="board_item_info">

                                <h3>
                                    <?= htmlspecialchars($board["title"], ENT_QUOTES, "UTF-8") ?>
                                </h3>

                                <span class="category_tag">
                                    <?= htmlspecialchars($board["category"], ENT_QUOTES, "UTF-8") ?>
                                </span>

                            </div>

                            <span class="arrow">›</span>

                        </a>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <p class="empty_message">
                    まだ掲示板を作成していません。
                </p>

            <?php endif; ?>

            <a href="create_board.php" class="create_button">
                新しい掲示板を作成する
            </a>

        </section>

        <!-- 最近の投稿 -->
        <section class="mypage_section">

            <h2>最近の投稿</h2>

            <?php if (count($my_posts) > 0): ?>

                <div class="my_post_list">

                    <?php foreach ($my_posts as $post): ?>

                        <div class="my_post_item">

                            <p class="post_board">
                                <a href="board.php?id=<?= (int)$post["board_id"] ?>">
                                    <?= htmlspecialchars($post["board_title"], ENT_QUOTES, "UTF-8") ?>
                                </a>
                            </p>

                            <p class="post_message">
                                <?= nl2br(htmlspecialchars($post["message"], ENT_QUOTES, "UTF-8")) ?>
                            </p>

                            <p class="post_date">
                                <?= htmlspecialchars($post["created_at"], ENT_QUOTES, "UTF-8") ?>
                            </p>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <p class="empty_message">
                    まだ投稿していません。
                </p>

            <?php endif; ?>

        </section>

        <!-- ログアウト -->
        <div class="logout_area">

            <form action="logout.php" method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
                >

                <button type="submit" class="logout_button">
                    ログアウト
                </button>

            </form>

        </div>

        <!-- トップページへ戻る -->
        <a href="index.php" class="back_button">
            掲示板一覧に戻る
        </a>

    </main>

</body>
</html>
