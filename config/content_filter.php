<?php

function containsInappropriateJapaneseWord(string $text): bool
{
    $dictionary_path = __DIR__ . "/../data/moderation/Offensive.txt";

    if (!is_file($dictionary_path) || !is_readable($dictionary_path)) {
        throw new RuntimeException("NGワード辞書を読み込めません: " . $dictionary_path);
    }

    $words = file(
        $dictionary_path,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($words === false) {
        throw new RuntimeException("NGワード辞書の読み込みに失敗しました。");
    }

    // 全角英数字などの表記差をある程度そろえる
    $normalized_text = mb_convert_kana($text, "asKV", "UTF-8");
    $normalized_text = mb_strtolower($normalized_text, "UTF-8");

    foreach ($words as $word) {

        // UTF-8 BOMや前後の空白を除去
        $word = preg_replace('/^\xEF\xBB\xBF/', '', trim($word));

        if ($word === "" || str_starts_with($word, "#")) {
            continue;
        }

        $normalized_word = mb_convert_kana($word, "asKV", "UTF-8");
        $normalized_word = mb_strtolower($normalized_word, "UTF-8");

        if (mb_stripos($normalized_text, $normalized_word, 0, "UTF-8") !== false) {
            return true;
        }
    }

    return false;
}

?>
