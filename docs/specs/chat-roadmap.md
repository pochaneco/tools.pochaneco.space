# AI チャット拡張ロードマップ

最終更新: 2026-04-19
対象機能: `/chat` (さくらのAI Engine 経由、`laravel/ai` SDK)

## 目的

現在の AI チャット (PR #7〜#9 で導入) を「モダンなチャット UX」へ段階的に拡張する。各フェーズを **独立した PR** として実装し、履歴を残した上でマージする。

## 技術スタック

- Laravel 13 / Inertia 2 / Vue 3 / Tailwind 4 / TypeScript
- `laravel/ai` ^0.6 (AI SDK, openrouter ドライバ経由で Sakura に接続)
- Pest 4 テスト
- reka-ui コンポーネント (`resources/js/components/ui/*`)

## Phase 1: 会話一覧サイドバー ✅ 完了

**PR**: [#9](https://github.com/pochaneco/tools.pochaneco.space/pull/9)

- `/chat/conversations` API 4 ルート (index / show / update / destroy)
- `ConversationPolicy` で所有者チェック
- Chat ページ 2 カラム (desktop) + Sheet drawer (mobile)
- リネーム (inline) / 削除 (confirm) / 新規会話ボタン
- `Message::$touches = ['conversation']` で `updated_at` 自動更新 → 最新メッセージ順ソート

## Phase 2: Markdown + コードハイライト

**目的**: AI 応答の読みやすさ向上。

### 決定

- **ライブラリ**: `marked` + `highlight.js`
- **適用範囲**: assistant messages のみ markdown 描画 (user messages は plain text で whitespace-pre-wrap 維持)
- **セキュリティ**: `DOMPurify` で sanitize、raw HTML 埋め込み禁止
- **ストリーミング対応**: delta 受信ごとに再レンダリングは重いので、`requestAnimationFrame` で 60fps 上限 throttle。ただし送信が遅い場合を許容
- **対応言語**: `common` バンドル (JS/TS/PHP/Python/Go/Rust/SQL/Bash/JSON/YAML/Vue/HTML/CSS 等)
- **ダーク対応**: `highlight.js/styles/github-dark.css` と `github.css` を media query で出し分け

### 受け入れ基準

- AI 応答に含まれる `**bold**` / ````code blocks``` / lists / links / tables が整形表示される
- コードブロックは言語識別 + シンタックスハイライト
- XSS テスト: `<script>alert(1)</script>` を含む応答が無害化される
- ストリーミング中も段階的にレンダーされる

---

## Phase 3: AI 自動タイトル生成

**目的**: `先頭60字 slice` の仮タイトルを、AI 要約の短いタイトルに置き換え。

### 決定

- **トリガー**: 初回 assistant 応答完了時 (最初の 2 件メッセージが揃ったタイミング)
- **方式**: **バックグラウンド Job** (`GenerateConversationTitle`) で非同期に SDK を呼ぶ。同期だと `POST /chat/message` の応答がさらに遅くなる
- **モデル**: 同じ provider (`sakura`) + 軽量モデル (`gpt-oss-120b` でもいいし、`Qwen3-Coder-30B` の方が速くて安い候補)。コスト最小化のため **max_tokens 20** で打ち切り
- **プロンプト例**:
  ```
  Instructions: Summarize the following user question in 5 words or less, in Japanese. Output only the title, no quotes or punctuation.
  User: {最初のユーザーメッセージ}
  ```
- **UI 反映**: バックエンドで title 更新後、フロントは次回 conversation list fetch で反映される (polling なし、ユーザーが切替時に見る)
- **エラー時**: Job 失敗しても仮タイトル (先頭60字) のままで OK

### 受け入れ基準

- 新規会話の初回 assistant 応答完了後、数秒で title が AI 要約に置き換わる
- 既存会話 (タイトル手動設定済み) は上書きされない
- Job 失敗時も UI は壊れず、再送できる

---

## Phase 4: 応答の再生成ボタン (Regenerate)

**目的**: 不満足な応答を捨てて同じ user message で作り直す。

### 決定

- **UI**: 最新 assistant message の下に「🔄 再生成」ボタン。ストリーミング中は非表示
- **動作**:
  1. 最新 assistant message を DB から削除 (`Message::latest()->first()->delete()`)
  2. 最新 user message の content で `/chat/message` を再度呼ぶ (conversation_id 付き)
- **実装**: 新規エンドポイント `POST /chat/regenerate` を追加 (ChatController::regenerate)。内部で同じストリーミングロジックを流用
- **停止ボタン**: 既存の AbortController ベース実装を流用 (Phase 1 実装済み)

### 受け入れ基準

- 「再生成」ボタンで最新 assistant message が消えて新しい応答が SSE で流れてくる
- 履歴 (user message 群) は保持
- 同時並行呼び出し防止 (送信中は disable)

---

## Phase 5: メッセージ毎モデル切替

**目的**: 用途 (汎用 / 日本語 / コーディング) に応じてメッセージ単位で AI モデルを使い分け。

### 決定

- **対応モデル**:
  1. `gpt-oss-120b` — 汎用 / デフォルト
  2. `llm-jp-3.1-8x13b-instruct4` — 日本語特化
  3. `Qwen3-Coder-480B-A35B-Instruct-FP8` — 高性能コーディング
  4. `Qwen3-Coder-30B-A3B-Instruct` — 軽量コーディング
- **DB 変更**: `messages` テーブルに `model` カラム追加 (string nullable)。user message には NULL、assistant message に使用モデル記録
- **既存 `conversations.model`**: 「その会話のデフォルト」として維持 (初回選択値)。以降メッセージ単位で override
- **UI**: Input 近くに DropdownMenu で現在選択モデル表示 + 切替
- **セッション記憶**: 最後に選んだモデルを localStorage に保存して次回起動時に復元
- **ラベル**: モデルフルネームは長いので短縮ラベルを定義 (例: `gpt-oss` / `llm-jp` / `qwen-coder-480` / `qwen-coder-30`)

### 受け入れ基準

- Input 横にモデル selector がある
- 選択が次の送信に反映される
- 過去 assistant messages にも使用モデルのバッジが表示される
- ブラウザリロードで前回選択が復元される

---

## Phase 6: 履歴トランケーション (token 数ベース)

**目的**: 会話が長くなると context window を超えて失敗 / 過課金になるのを防ぐ。

### 決定

- **カウント方法**: `yethee/tiktoken-php` または同等。OpenAI 互換のエンコーダで近似
- **上限**: モデル別に定義
  - `gpt-oss-120b`: 128k (実際の context window に合わせる、余裕もって 100k)
  - `llm-jp-3.1`: 32k 想定
  - `Qwen3-Coder`: 128k 以上 (要確認)
- **切り落とし戦略**: system prompt 必ず保持 + 最新メッセージから詰めていき、溢れた古いメッセージを捨てる
- **UI**: 切り落としが発生した時に「過去のN件を送信対象から除外」バナー (目立たない程度)
- **設定**: `config/ai.php` に `chat.max_context_tokens` 配列追加

### 受け入れ基準

- 長い会話 (1000 件超) で送信してもエラーにならない
- system + 最新数件が必ず含まれる
- トランケーション発生時にテストで捕捉できる

---

## Phase 7: トークン使用量 (累計) 表示

**目的**: コスト把握 / 利用量感覚。

### 決定

- **表示位置**: 会話ヘッダ (chat main column の上部) に累計を表示
- **フォーマット**: `prompt: 1,250 / completion: 880 / total: 2,130 tokens`
- **計算**: `messages.prompt_tokens` と `messages.completion_tokens` を sum (既に DB に保存済み)
- **実装**: `GET /chat/conversations/{id}` レスポンスに `usage: {prompt, completion, total}` 追加。フロントはそれを表示
- **i18n**: 短縮表示用キー追加 (例: `trans('chat.tokens_used', {total: ...})`)

### 受け入れ基準

- 会話ヘッダに累計トークン数が表示される
- 新規メッセージ送受信後に数値が更新される
- 空の会話では 0 表示

---

## 実装サイクル

各 Phase は **1 エージェント = 1 PR** で実装:

1. Agent を worktree で起動 (isolation: worktree)
2. Agent がブランチ作成 → 実装 → テスト → commit → push → PR 作成
3. メインセッション側で PR の CI を watch
4. 緑になったら手動レビュー (diff check) → `squash & delete-branch` でマージ
5. worktree を掃除
6. 次の Phase を Agent に指示

**制約**:
- 各 PR は独立にレビュー/リバート可能にする (ファイル衝突を最小化)
- 既存テストは絶対に赤くしない
- Lint / Build / Test 全緑で push
- 設計変更が必要なら先にこの文書を更新

## スコープ外 (当面やらない)

- マルチモーダル (画像/音声入力)
- Export (Markdown ダウンロード / 共有 URL / team 共有)
- 音声出力 (TTS)
- ページネーション (100 件上限で十分)
- RAG / メンテナンス要約エージェント
- フロント SSR (Inertia prop で初期データ注入)
