
<?php

require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";

// ログイン確認
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["username"]) ||
    !is_numeric($_SESSION["user_id"])
) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];

try {

    // ユーザー情報を取得
    $sql = "
        SELECT
            username,
            two_factor_enabled
        FROM users
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":id" => $user_id
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    // セッションのユーザー名を最新情報に更新
    $_SESSION["username"] = $user["username"];

} catch (PDOException $e) {

    error_log($e->getMessage());

    http_response_code(500);
    exit("ユーザー情報の取得に失敗しました。");

}

$username = $user["username"];
$two_factor_enabled = (int)$user["two_factor_enabled"];

?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>アカウント設定</title>

    <link rel="stylesheet" href="./style/account_settings.css">
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


    <!-- 設定ページ -->
    <main class="settings">

        <div class="settings_heading">

            <p class="settings_subtitle">ACCOUNT</p>

            <h1>アカウント設定</h1>

            <p class="settings_description">
                アカウント情報やセキュリティに関する設定を管理できます。
            </p>

        </div>


        <!-- アカウント情報 -->
        <section class="account_info">

            <div class="account_icon">
                <?= htmlspecialchars(mb_substr($username, 0, 1), ENT_QUOTES, "UTF-8") ?>
            </div>

            <div class="account_text">

                <p class="account_label">ログイン中のアカウント</p>

                <h2>
                    <?= htmlspecialchars($username, ENT_QUOTES, "UTF-8") ?>
                </h2>

                <p class="account_id">
                    USER ID：<?= $user_id ?>
                </p>

            </div>

        </section>


        <!-- アカウント情報の設定 -->
        <section class="setting_section">

            <div class="section_heading">

                <h2>アカウント情報</h2>

                <p>登録しているアカウント情報を変更できます。</p>

            </div>


            <a href="change_username.php" class="setting_card">

                <div class="setting_icon">
                    <span>＠</span>
                </div>

                <div class="setting_content">

                    <h3>ユーザー名の変更</h3>

                    <p>
                        掲示板で表示されるユーザー名を変更します。
                    </p>

                </div>

                <span class="setting_arrow">›</span>

            </a>


            <a href="change_password.php" class="setting_card">

                <div class="setting_icon">
                    <span>●</span>
                </div>

                <div class="setting_content">

                    <h3>パスワードの変更</h3>

                    <p>
                        アカウントにログインするパスワードを変更します。
                    </p>

                </div>

                <span class="setting_arrow">›</span>

            </a>

        </section>


        <!-- セキュリティ設定 -->
        <section class="setting_section">

            <div class="section_heading">

                <h2>セキュリティ</h2>

                <p>アカウントの安全性を高める設定です。</p>

            </div>


            <a href="two_factor_settings.php" class="setting_card">

                <div class="setting_icon security_icon">
                    <span>✓</span>
                </div>

                <div class="setting_content">

                    <h3>2段階認証</h3>

                    <p>
                        Google Authenticatorを使用して、
                        ログイン時のセキュリティを強化します。
                    </p>

                    <span class="security_status">

                        <?php if ($two_factor_enabled === 1): ?>

                            <span class="status_enabled">有効</span>

                        <?php else: ?>

                            <span class="status_disabled">無効</span>

                        <?php endif; ?>

                    </span>

                </div>

                <span class="setting_arrow">›</span>

            </a>

        </section>


        <!-- 戻る -->
        <div class="settings_footer">

            <a href="mypage.php" class="back_button">
                マイページに戻る
            </a>

        </div>

    </main>

</body>
</html>
