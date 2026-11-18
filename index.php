
<?php
require_once __DIR__ . "/config/session.php";

require_once "config/db.php";

// ジャンル一覧
$categories = ["雑談", "ニュース", "ゲーム", "アニメ", "マンガ", "音楽", "その他"];


// URLから検索条件を取得
$category = $_GET["category"] ?? "";
$keyword = $_GET["keyword"] ?? "";
$page_input = $_GET["page"] ?? "1";


// 入力値の型を確認
if (!is_string($category)) {
    $category = "";
}

if (!is_string($keyword)) {
    $keyword = "";
}

if (!is_string($page_input) || !ctype_digit($page_input)) {
    $page_input = "1";
}

$keyword = trim($keyword);


// ジャンルが正しいか確認
if ($category !== "" && !in_array($category, $categories, true)) {
    $category = "";
}


// キーワードの長さを制限
if (mb_strlen($keyword) > 500) {
    $keyword = mb_substr($keyword, 0, 500);
}


// 検索条件を格納
$where = [];
$params = [];


// ジャンルが選択されている場合
if ($category !== "") {
    $where[] = "boards.category = :category";
    $params[":category"] = $category;
}


// キーワードが入力されている場合
if ($keyword !== "") {
    $where[] = "(boards.title LIKE :keyword_title OR boards.description LIKE :keyword_description)";
    $params[":keyword_title"] = "%" . $keyword . "%";
    $params[":keyword_description"] = "%" . $keyword . "%";
}


// SQLの共通部分
$sql = "FROM boards
        INNER JOIN users ON boards.user_id = users.id";


// 検索条件がある場合
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}


try {

    // 掲示板の総件数を取得
    $count_sql = "SELECT COUNT(*) " . $sql;

    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);

    $total_boards = (int)$count_stmt->fetchColumn();


    // ページネーションの設定
    $per_page = 10;

    // 現在のページ番号
    $page = max(1, (int)$page_input);

    // 総ページ数
    $total_pages = max(1, (int)ceil($total_boards / $per_page));

    // 存在しないページ番号へのアクセスを防止
    $page = min($page, $total_pages);

    // 取得開始位置
    $offset = ($page - 1) * $per_page;


    // 掲示板一覧を取得
    $board_sql = "SELECT boards.*, users.username " . $sql;

    // 最後の投稿日時が新しい順に並べる
    $board_sql .= " ORDER BY
        (
            SELECT MAX(posts.created_at)
            FROM posts
            WHERE posts.board_id = boards.id
            AND posts.is_deleted = 0
        ) DESC,
        boards.created_at DESC,
        boards.id DESC";

    $board_sql .= " LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($board_sql);


    // 検索条件を設定
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    // LIMITとOFFSETは整数として設定
    $stmt->bindValue(":limit", $per_page, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);

    $stmt->execute();

    $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>掲示板一覧</title>
    <link rel="stylesheet" href="./style/index.css">
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

            <?php if (isset($_SESSION["user_id"])): ?>

                <p>
                    <?= htmlspecialchars($_SESSION["username"], ENT_QUOTES, "UTF-8") ?> さん、ログイン中
                </p>

                <a href="mypage.php">マイページ</a>
                <br>

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

            <?php else: ?>

                <a href="login.php">ログイン</a>

            <?php endif; ?>

        </div>

    </header>


    <!-- メイン画像 -->
    <div class="main_img">
        <img src="./img/7ch_logo_min.svg" alt="掲示板サイトのロゴ">
    </div>


    <!-- 検索フォーム -->
    <div class="search_area">

        <form action="index.php" method="get" class="search_form">

            <!-- ジャンル選択 -->
            <select name="category">
                <option value="">すべてのジャンル</option>

                <?php foreach ($categories as $item): ?>

                    <option value="<?= htmlspecialchars($item, ENT_QUOTES, "UTF-8") ?>"
                        <?= $category === $item ? "selected" : "" ?>>
                        <?= htmlspecialchars($item, ENT_QUOTES, "UTF-8") ?>
                    </option>

                <?php endforeach; ?>

            </select>


            <!-- フリーワード入力 -->
            <input
                type="text"
                name="keyword"
                placeholder="キーワードを入力"
                value="<?= htmlspecialchars($keyword, ENT_QUOTES, "UTF-8") ?>"
            >

            <button type="submit">検索</button>

        </form>

    </div>


    <div class="container">


        <!-- 左側：ジャンル一覧 -->
        <div class="category_list">

            <a href="index.php" class="<?= $category === "" ? "active" : "" ?>">トップ</a>

            <?php foreach ($categories as $item): ?>

                <a href="index.php?category=<?= urlencode($item) ?>"
                    class="<?= $category === $item ? "active" : "" ?>">
                    <?= htmlspecialchars($item, ENT_QUOTES, "UTF-8") ?>
                </a>

            <?php endforeach; ?>

        </div>


        <!-- 中央：掲示板一覧 -->
        <div class="main">

            <h2>

                <?php if ($keyword !== ""): ?>

                    検索結果：<?= htmlspecialchars($keyword, ENT_QUOTES, "UTF-8") ?>

                <?php elseif ($category !== ""): ?>

                    <?= htmlspecialchars($category, ENT_QUOTES, "UTF-8") ?>の掲示板

                <?php else: ?>

                    すべての掲示板

                <?php endif; ?>

            </h2>


            <?php if (isset($_SESSION["user_id"])): ?>

                <p class="create_board">
                    <a href="create_board.php">新しい掲示板を作成</a>
                </p>

            <?php else: ?>

                <p>
                    掲示板を作成するにはログインしてください。
                </p>

            <?php endif; ?>


            <hr>


            <!-- 掲示板一覧 -->
            <?php if (count($boards) > 0): ?>

                <?php foreach ($boards as $board): ?>

                    <div class="boards">

                        <div class="boards_title">

                            <span class="category_tag">
                                <?= htmlspecialchars($board["category"], ENT_QUOTES, "UTF-8") ?>
                            </span>

                            <h3>
                                <a href="board.php?id=<?= (int)$board["id"] ?>">
                                    <?= htmlspecialchars($board["title"], ENT_QUOTES, "UTF-8") ?>
                                </a>
                            </h3>

                        </div>


                        <div class="boards_a">

                            <p>
                                <?= htmlspecialchars($board["description"], ENT_QUOTES, "UTF-8") ?>
                            </p>

                            <p>
                                作成者：
                                <?= htmlspecialchars($board["username"], ENT_QUOTES, "UTF-8") ?>
                            </p>

                        </div>

                    </div>

                <?php endforeach; ?>


            <?php else: ?>

                <p>該当する掲示板はありません。</p>

            <?php endif; ?>


            <!-- ページネーション -->
            <?php if ($total_pages > 1): ?>

                <div class="pagination">

                    <!-- 前へ -->
                    <?php if ($page > 1): ?>

                        <a href="index.php?<?= http_build_query([
                            "category" => $category,
                            "keyword" => $keyword,
                            "page" => $page - 1
                        ]) ?>">前へ</a>

                    <?php endif; ?>


                    <!-- ページ番号 -->
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>

                        <a href="index.php?<?= http_build_query([
                            "category" => $category,
                            "keyword" => $keyword,
                            "page" => $i
                        ]) ?>"
                            class="<?= $page === $i ? "active" : "" ?>">
                            <?= $i ?>
                        </a>

                    <?php endfor; ?>


                    <!-- 次へ -->
                    <?php if ($page < $total_pages): ?>

                        <a href="index.php?<?= http_build_query([
                            "category" => $category,
                            "keyword" => $keyword,
                            "page" => $page + 1
                        ]) ?>">次へ</a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


        </div>

    </div>

</body>
</html>
