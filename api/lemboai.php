<?php
/**
 * POST api/lemboai.php   (JSON, X-CSRF-Token header)
 *   { "message": "How much did I earn this month?", "history": [{role, content}, …] }
 *   → { ok: true, reply: "…" }   or   { ok: false, error: "…" }
 *
 * The browser only ever talks to this endpoint; the Groq key never leaves the server.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/lemboai.php';

$userId = require_login();
verify_csrf();

if (!lemboai_enabled()) {
    json_response(['ok' => false, 'error' => 'LemboAI is not set up yet. Add your Groq API key to config/local.php.'], 503);
}

$input    = json_decode((string) file_get_contents('php://input'), true);
$question = is_array($input) && is_string($input['message'] ?? null) ? trim($input['message']) : '';

if ($question === '') {
    json_response(['ok' => false, 'error' => 'Type a question first.'], 422);
}
if (mb_strlen($question) > LEMBOAI_MAX_MESSAGE) {
    json_response(['ok' => false, 'error' => 'That question is too long. Keep it under ' . LEMBOAI_MAX_MESSAGE . ' characters.'], 422);
}

$wait = lemboai_rate_limit();
if ($wait > 0) {
    json_response(['ok' => false, 'error' => 'You’ve asked a lot of questions in a short time. Try again in ' . ceil($wait / 60) . ' min.'], 429);
}

// The session isn't needed any more; release its lock so the rest of the app stays responsive
// while we wait for the AI.
session_write_close();

try {
    $reply = lemboai_answer($userId, $question, sanitize_history($input['history'] ?? []));
    json_response(['ok' => true, 'reply' => $reply]);
} catch (LemboAiException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 502);
}
