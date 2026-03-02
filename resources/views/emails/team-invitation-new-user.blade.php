<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[{{ config('app.name') }}] {{ __('teams.new_user_invitation_subject', ['team' => $team->name]) }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #fffef5; border-radius: 8px; padding: 30px; margin-bottom: 20px;">
        <h1 style="color: #2d3748; margin: 0 0 20px 0;">[{{ config('app.name') }}] {{ __('teams.new_user_invitation_subject', ['team' => $team->name]) }}</h1>

        <p>{{ $inviter->name }} さんがあなたを <strong>{{ $team->name }}</strong> チームに招待しました。</p>

        @if($team->description)
        <p style="color: #718096; font-style: italic;">{{ $team->description }}</p>
        @endif

        <p>このチームに参加するには、以下のボタンをクリックしてアカウントを作成してください：</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $acceptUrl }}" style="display: inline-block; background-color: #000000; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;">アカウントを作成してチームに参加</a>
        </div>

        <p style="color: #718096; font-size: 14px;">このリンクは7日間有効です。</p>
    </div>

    <p style="color: #718096; font-size: 12px; text-align: center;">
        このメールに心当たりがない場合は、無視してください。
    </p>
</body>
</html>
