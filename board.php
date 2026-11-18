
<?php
require_once __DIR__ . "/config/session.php";

require_once "config/db.php";
require_once __DIR__ . "/config/content_filter.php";

// CSRFトークンの発行
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// URLから掲示板IDを取得
$board_id = $_GET["id"] ?? "";

if (!is_string($board_id) || !ctype_digit($board_id) || (int)$board_id < 1) {
    exit("掲示板が指定されていません。");
}

// 検索キーワードを取得
$keyword = $_GET["keyword"] ?? "";

if (!is_string($keyword)) {
    $keyword = "";
}

$keyword = trim($keyword);

// 掲示板情報を取得
$sql = "SELECT * FROM boards WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([":id" => $board_id]);
$board = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$board) {
    exit("掲示板が見つかりません。");
}

// 投稿・削除処理
$message = "";

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

    if (!isset($_SESSION["user_id"])) {
        exit("ログインしてください。");
    }

    $action = $_POST["action"] ?? "";

    if (!is_string($action)) {
        exit("不正な操作です。");
    }

    // 投稿削除
    if ($action === "delete") {

        $post_id = $_POST["post_id"] ?? "";

        if (!is_string($post_id) || !ctype_digit($post_id) || (int)$post_id < 1) {
            exit("不正な投稿IDです。");
        }

        // 最初の投稿IDを取得
        $sql = "SELECT id FROM posts
                WHERE board_id = :board_id
                ORDER BY created_at ASC, id ASC
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([":board_id" => $board_id]);
        $first_post_id = $stmt->fetchColumn();

        // 最初の投稿は削除できない
        if ((string)$post_id === (string)$first_post_id) {
            exit("最初の投稿は削除できません。");
        }

        // 投稿を論理削除
        $sql = "UPDATE posts
                SET is_deleted = 1
                WHERE id = :post_id
                AND board_id = :board_id
                AND user_id = :user_id
                AND is_deleted = 0";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":post_id" => $post_id,
            ":board_id" => $board_id,
            ":user_id" => $_SESSION["user_id"]
        ]);

        header("Location: board.php?id=" . $board_id);
        exit;

    // 掲示板削除
    } elseif ($action === "delete_board") {

        $sql = "DELETE FROM boards
                WHERE id = :board_id
                AND user_id = :user_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":board_id" => $board_id,
            ":user_id" => $_SESSION["user_id"]
        ]);

        header("Location: index.php");
        exit;

    // 新規投稿
    } elseif ($action === "create_post") {

        $post_message = $_POST["message"] ?? "";

        if (!is_string($post_message)) {

            $message = "不正な投稿内容です。";

        } else {

            $post_message = trim($post_message);

            if ($post_message === "") {

                $message = "投稿内容を入力してください。";

            } elseif (mb_strlen($post_message) > 1000) {

                $message = "投稿内容は1000文字以内にしてください。";

            } else {

                try {

                    // NGワード辞書で投稿内容を検査
                    if (containsInappropriateJapaneseWord($post_message)) {

                        $message = "不適切な表現が含まれています。投稿内容を修正してください。";

                    } else {

                        $sql = "INSERT INTO posts (board_id, user_id, message)
                                VALUES (:board_id, :user_id, :message)";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ":board_id" => $board_id,
                            ":user_id" => $_SESSION["user_id"],
                            ":message" => $post_message
                        ]);

                        // 新しい投稿がある1ページ目へ移動
                        header("Location: board.php?id=" . $board_id . "&page=1");
                        exit;
                    }

                } catch (RuntimeException $e) {

                    error_log($e->getMessage());
                    $message = "投稿チェックを実行できませんでした。時間をおいて再度お試しください。";

                }
            }
        }
    }
}

// 最初の投稿を取得
$sql = "SELECT posts.*, users.username
        FROM posts
        INNER JOIN users ON posts.user_id = users.id
        WHERE posts.board_id = :board_id
        ORDER BY posts.created_at ASC, posts.id ASC
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([":board_id" => $board_id]);
$first_post = $stmt->fetch(PDO::FETCH_ASSOC);

// 最初の投稿ID
$first_post_id = $first_post["id"] ?? null;

// 通常投稿の検索条件
$where_sql = "posts.board_id = :board_id";

if ($first_post_id !== null) {
    $where_sql .= " AND posts.id != :first_post_id";
}

// 検索キーワードがある場合
if ($keyword !== "") {
    $where_sql .= " AND posts.message LIKE :keyword";
    $where_sql .= " AND posts.is_deleted = 0";
}

// 通常投稿の総件数を取得
$count_sql = "SELECT COUNT(*)
              FROM posts
              WHERE " . $where_sql;

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->bindValue(":board_id", $board_id, PDO::PARAM_INT);

if ($first_post_id !== null) {
    $count_stmt->bindValue(":first_post_id", $first_post_id, PDO::PARAM_INT);
}

if ($keyword !== "") {
    $count_stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
}

$count_stmt->execute();
$total_posts = (int)$count_stmt->fetchColumn();

// ページネーション
$per_page = 10;

$page = $_GET["page"] ?? "1";

if (!is_string($page) || !ctype_digit($page)) {
    $page = "1";
}

$page = max(1, (int)$page);

$total_pages = max(1, (int)ceil($total_posts / $per_page));
$page = min($page, $total_pages);

$offset = ($page - 1) * $per_page;

// 通常投稿を取得
$sql = "SELECT posts.*, users.username,
        (
            SELECT COUNT(*)
            FROM posts AS older_posts
            WHERE older_posts.board_id = posts.board_id
            AND older_posts.id != :first_post_id_number
            AND (
                older_posts.created_at < posts.created_at
                OR (
                    older_posts.created_at = posts.created_at
                    AND older_posts.id < posts.id
                )
            )
        ) + 2 AS post_number
        FROM posts
        INNER JOIN users ON posts.user_id = users.id
        WHERE " . $where_sql;

// 最新の投稿から取得
$sql .= " ORDER BY posts.created_at DESC, posts.id DESC
          LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(":board_id", $board_id, PDO::PARAM_INT);

if ($first_post_id !== null) {
    $stmt->bindValue(":first_post_id", $first_post_id, PDO::PARAM_INT);
    $stmt->bindValue(":first_post_id_number", $first_post_id, PDO::PARAM_INT);
} else {
    // 最初の投稿が存在しない場合
    $stmt->bindValue(":first_post_id_number", 0, PDO::PARAM_INT);
}

if ($keyword !== "") {
    $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
}

$stmt->bindValue(":limit", $per_page, PDO::PARAM_INT);
$stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
$stmt->execute();

// 取得した投稿をページ内で古い順に並べ替える
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$posts = array_reverse($posts);

?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($board["title"], ENT_QUOTES, "UTF-8") ?></title>

    <link rel="stylesheet" href="./style/board.css">
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

    <main class="board_page">

        <!-- 掲示板情報 -->
        <div class="board_header">

            <h1>
                <?= htmlspecialchars($board["title"], ENT_QUOTES, "UTF-8") ?>
            </h1>

            <p>
                ジャンル：
                <span class="category_tag">
                    <?= htmlspecialchars($board["category"], ENT_QUOTES, "UTF-8") ?>
                </span>
            </p>

            <p class="board_description">
                <?= nl2br(htmlspecialchars($board["description"], ENT_QUOTES, "UTF-8")) ?>
            </p>

            <?php if (isset($_SESSION["user_id"]) && $_SESSION["user_id"] == $board["user_id"]): ?>

                <form method="POST"
                    onsubmit="return confirm('この掲示板を削除しますか？投稿もすべて削除されます。');">

                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                    <input type="hidden" name="action" value="delete_board">

                    <button type="submit" class="delete_board_button">
                        掲示板を削除する
                    </button>

                </form>

            <?php endif; ?>

        </div>

        <!-- 投稿一覧 -->
        <section class="post_list">

            <!-- 投稿一覧の見出しと検索フォーム -->
            <div class="post_list_header">

                <h2>投稿一覧</h2>

                <form method="GET" action="board.php" class="post_search">

                    <input type="hidden" name="id" value="<?= (int)$board_id ?>">

                    <input
                        type="text"
                        name="keyword"
                        value="<?= htmlspecialchars($keyword, ENT_QUOTES, "UTF-8") ?>"
                        placeholder="投稿を検索"
                    >

                    <button type="submit">検索</button>

                    <?php if ($keyword !== ""): ?>

                        <a href="board.php?id=<?= (int)$board_id ?>">
                            解除
                        </a>

                    <?php endif; ?>

                </form>

            </div>

            <?php if ($keyword !== ""): ?>

                <p class="search_result">
                    「<?= htmlspecialchars($keyword, ENT_QUOTES, "UTF-8") ?>」の検索結果：
                    <?= $total_posts ?>件
                </p>

            <?php endif; ?>

            <!-- 最初の投稿 -->
            <?php if ($first_post): ?>

                <div class="post_item fixed_post">

                    <p class="post_info">
                        1：
                        <?= htmlspecialchars($first_post["username"], ENT_QUOTES, "UTF-8") ?>：
                        <?= htmlspecialchars($first_post["created_at"], ENT_QUOTES, "UTF-8") ?>
                    </p>

                    <div class="post_message">

                        <?php if ($first_post["is_deleted"]): ?>

                            <p class="deleted_message">
                                この投稿は削除されました。
                            </p>

                        <?php else: ?>

                            <?= nl2br(htmlspecialchars($first_post["message"], ENT_QUOTES, "UTF-8")) ?>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endif; ?>

            <!-- 通常投稿 -->
            <?php if (count($posts) > 0): ?>

                <?php foreach ($posts as $post): ?>

                    <div class="post_item">

                        <p class="post_info">
                            <?= (int)$post["post_number"] ?>：
                            <?= htmlspecialchars($post["username"], ENT_QUOTES, "UTF-8") ?>：
                            <?= htmlspecialchars($post["created_at"], ENT_QUOTES, "UTF-8") ?>
                        </p>

                        <div class="post_message">

                            <?php if ($post["is_deleted"]): ?>

                                <p class="deleted_message">
                                    この投稿は削除されました。
                                </p>

                            <?php else: ?>

                                <?= nl2br(htmlspecialchars($post["message"], ENT_QUOTES, "UTF-8")) ?>

                                <?php if (isset($_SESSION["user_id"]) && $_SESSION["user_id"] == $post["user_id"]): ?>

                                    <form method="POST"
                                        onsubmit="return confirm('この投稿を削除しますか？');">

                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                                        <input type="hidden" name="action" value="delete">

                                        <input type="hidden" name="post_id" value="<?= (int)$post["id"] ?>">

                                        <button type="submit" class="delete_post_button">
                                            削除
                                        </button>

                                    </form>

                                <?php endif; ?>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php elseif ($keyword !== ""): ?>

                <p>該当する投稿はありません。</p>

            <?php elseif (!$first_post): ?>

                <p>まだ投稿はありません。</p>

            <?php endif; ?>

            <!-- ページネーション -->
            <?php if ($total_pages > 1): ?>

                <div class="pagination">

                    <?php if ($page > 1): ?>

                        <a href="board.php?<?= http_build_query([
                            "id" => $board_id,
                            "keyword" => $keyword,
                            "page" => $page - 1
                        ]) ?>">
                            前へ
                        </a>

                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>

                        <a href="board.php?<?= http_build_query([
                            "id" => $board_id,
                            "keyword" => $keyword,
                            "page" => $i
                        ]) ?>"
                            class="<?= $page === $i ? "active" : "" ?>">

                            <?= $i ?>

                        </a>

                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>

                        <a href="board.php?<?= http_build_query([
                            "id" => $board_id,
                            "keyword" => $keyword,
                            "page" => $page + 1
                        ]) ?>">
                            次へ
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>

        <!-- 投稿フォーム -->
        <section class="post_form">

            <h2>投稿する</h2>

            <?php if (isset($_SESSION["user_id"])): ?>

                <form method="POST">

                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                    <input type="hidden" name="action" value="create_post">

                    <textarea
                        name="message"
                        rows="5"
                        maxlength="1000"
                        placeholder="投稿内容を入力してください"
                        required
                    ></textarea>

                    <p class="error_message">
                        <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
                    </p>

                    <button type="submit" class="submit_button">
                        投稿する
                    </button>

                </form>

            <?php else: ?>

                <p>投稿するにはログインしてください。</p>

                <a href="login.php" class="login_link">
                    ログインはこちら
                </a>

            <?php endif; ?>

        </section>

        <!-- 掲示板一覧へ戻る -->
        <a href="index.php" class="back_button">
            掲示板一覧に戻る
        </a>

    </main>

</body>
</html>
