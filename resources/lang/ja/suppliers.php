<?php

declare(strict_types=1);

return [
    'title' => 'サプライヤー',
    'search_placeholder' => '名称またはコードで検索',
    'table' => [
        'name' => '名称',
        'code' => 'コード',
        'email' => 'メール',
        'phone' => '電話番号',
        'actions' => '操作',
        'empty' => 'サプライヤーがありません',
    ],
    'buttons' => [
        'new_supplier' => '新規サプライヤー',
        'edit' => '編集',
        'delete' => '削除',
        'cancel' => 'キャンセル',
        'save' => '保存',
        'saving' => '保存中...',
        'close' => '閉じる',
    ],
    'modal' => [
        'new_title' => '新規サプライヤー',
        'edit_title' => 'サプライヤーを編集',
    ],
    'form' => [
        'name' => '名称',
        'code' => 'コード',
        'email' => 'メール',
        'email_cc' => 'メールCC（カンマ区切り）',
        'email_cc_placeholder' => 'cc1@example.com, cc2@example.com',
        'contact_person' => '担当者',
        'phone' => '電話番号',
        'active' => '有効',
        'active_yes' => 'はい',
        'active_no' => 'いいえ',
        'auto_send_po' => '自動でPOを送信する',
        'address' => '住所',
    ],
    'detail' => [
        'title' => 'サプライヤー詳細',
        'purchase_orders' => '発注一覧',
        'empty_pos' => '発注がありません',
        'loading' => '読み込み中...',
    ],
    'delete' => [
        'confirm_title' => 'サプライヤーを削除しますか？',
        'confirm_text' => 'この操作は取り消せません。サプライヤーに関連する発注がある場合は削除できません。',
        'confirm_button' => '削除する',
        'deleting' => '削除中...',
        'has_pos_error' => '関連する発注があるため削除できません。',
        'deleted' => 'サプライヤーを削除しました。',
    ],
];
