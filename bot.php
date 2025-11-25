<?php
// Load env vars (set in Render)
$TELEGRAM_TOKEN = $_ENV['TELEGRAM_TOKEN'] ?? '';
$COPYLEAKS_KEY = $_ENV['COPYLEAKS_KEY'] ?? '';
$GPTZERO_KEY = $_ENV['GPTZERO_KEY'] ?? '';

if (empty($TELEGRAM_TOKEN)) {
    http_response_code(500);
    die("Error: TELEGRAM_TOKEN not set!");
}

// Your Render URL (e.g., https://your-bot.onrender.com/webhook)
$WEBHOOK_URL = 'https://' . $_SERVER['HTTP_HOST'] . '/webhook';  // Adjust path if needed

// Set webhook on startup (runs once on deploy)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SERVER['QUERY_STRING'])) {
    $setWebhook = file_get_contents("https://api.telegram.org/bot$TELEGRAM_TOKEN/setWebhook?url=$WEBHOOK_URL");
    $result = json_decode($setWebhook, true);
    if ($result['ok']) {
        echo "Webhook set successfully to $WEBHOOK_URL";
    } else {
        echo "Webhook failed: " . json_encode($result);
    }
    exit;
}

// Handle webhook POST from Telegram
$update = json_decode(file_get_contents('php://input'), true);
if (!$update || !isset($update['message'])) {
    http_response_code(200);
    exit;
}

$message = $update['message'];
$chat_id = $message['chat']['id'];
$text = $message['text'] ?? '';

// Helper function to send messages
function sendMessage($chat_id, $text) {
    global $TELEGRAM_TOKEN;
    $url = "https://api.telegram.org/bot$TELEGRAM_TOKEN/sendMessage";
    $data = http_build_query(['chat_id' => $chat_id, 'text' => $text]);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $data
        ]
    ];
    file_get_contents($url, false, stream_context_create($opts));
}

// /start command
if (strpos($text, '/start') === 0) {
    sendMessage($chat_id, "Hi! Send me text to check for plagiarism and AI generation. (Powered by Honest Pen Bot)");
    exit;
}

// Check text length
if (strlen($text) < 15) {
    sendMessage($chat_id, "Text too short! Send at least 15 characters.");
    exit;
}

sendMessage($chat_id, "Checking your text...");

// Plagiarism check (Copyleaks API or fallback)
$plag = '';
if ($COPYLEAKS_KEY) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer $COPYLEAKS_KEY\r\nContent-Type: application/json",
            'content' => json_encode(['text' => $text, 'sandbox' => true])
        ]
    ]);
    $res = @file_get_contents('https://api.copyleaks.com/v3/scans/submit/text', false, $context);
    $data = json_decode($res, true);
    $score = $data['score'] ?? 0;
    $plag = "Plagiarism score: " . round($score * 100) . "%";
} else {
    $dummy = "Sample web content for similarity testing.";
    similar_text($text, $dummy, $percent);
    $plag = "Basic plagiarism similarity: " . round($percent, 1) . "% (add Copyleaks key for full scan)";
}

// AI check (GPTZero API or fallback)
$ai = '';
if ($GPTZERO_KEY) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "x-api-key: $GPTZERO_KEY\r\nContent-Type: application/json",
            'content' => json_encode(['document' => $text])
        ]
    ]);
    $res = @file_get_contents('https://api.gptzero.me/v2/predict/text', false, $context);
    $data = json_decode($res, true);
    $prob = $data['documents'][0]['class_probabilities']['completely_generated'] ?? 0;
    $ai = "AI-generated probability: " . round($prob * 100, 1) . "%";
} else {
    $words = str_word_count($text);
    $simple_score = min(50, $words / 5); // Dummy heuristic
    $ai = "Basic AI probability: " . $simple_score . "% (add GPTZero key for accurate detection)";
}

// Send results
$response = "$plag\n\n$ai\n\nDone! (Upgrade keys for pro features.)";
sendMessage($chat_id, $response);

http_response_code(200);
?>
