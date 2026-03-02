<?php

return [
    // Team management
    'created_successfully' => 'チームを作成しました',
    'updated_successfully' => 'チームを更新しました',
    'deleted_successfully' => 'チームを削除しました',
    'member_added_successfully' => 'メンバーを追加しました',
    'member_removed_successfully' => 'メンバーを削除しました',
    'member_role_updated' => 'メンバーの権限を変更しました',
    'cannot_remove_owner' => 'オーナーは削除できません',
    'cannot_change_owner_role' => 'オーナーの権限は変更できません',
    'default_team_name' => ':nameのチーム',
    'default_team_description' => '個人用のデフォルトチーム',

    // Team invitations
    'invitation_sent' => '招待メールを送信しました',
    'invitation_resent' => '招待メールを再送しました',
    'invitation_expired' => 'この招待は期限切れです',
    'invitation_already_accepted' => 'この招待は既に承認されています',
    'invitation_already_sent' => 'このメールアドレスには既に招待を送信しています',
    'invitation_accepted' => 'チームへの参加が完了しました',
    'invitation_declined' => '招待を辞退しました',
    'user_already_member' => 'このユーザーは既にチームメンバーです',
    'already_team_member' => '既にこのチームのメンバーです',
    'login_to_accept_invitation' => 'ログインして招待を承認してください',
    'invitation_email_mismatch' => '招待されたメールアドレスと一致しません',

    // Email subjects
    'new_user_invitation_subject' => ':team チームへの招待',
    'existing_user_invitation_subject' => ':team チームへの招待',

    // UI labels
    'owner' => 'オーナー',
    'edit' => '編集',
    'invite_member' => 'メンバーを招待',
    'add_member_dialog_title' => 'メンバーを招待',
    'add_member_dialog_description' => 'メールアドレスで新しいメンバーを招待します',
    'email_address' => 'メールアドレス',
    'select_role' => '役割を選択',
    'select_role_placeholder' => '役割を選択してください',
    'add_member' => '招待を送信',
    'confirm_remove_member' => 'このメンバーを削除してもよろしいですか？',
    'invitation_details' => '招待の詳細',
    'invited_by' => '招待者',
    'role' => '役割',
    'accept_invitation' => '招待を承認',
    'decline_invitation' => '招待を辞退',
    'confirm_decline_invitation' => 'この招待を辞退してもよろしいですか？',

    // Roles
    'roles' => [
        'owner' => 'オーナー',
        'member' => 'メンバー',
    ],
];
