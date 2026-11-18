
<?php

// PHPのタイムゾーンを日本時間に設定
date_default_timezone_set('Asia/Tokyo');

// データベース接続設定（利用環境に合わせて変更）
$host = "localhost";
$dbname = "データベース名";
$username = "ユーザー名";
$password = "パスワード";

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // MySQLのタイムゾーンを日本時間に設定
    $pdo->exec("SET time_zone = '+09:00'");

} catch (PDOException $e) {

    error_log($e->getMessage());

    exit("データベースに接続できませんでした。");

}

?>
