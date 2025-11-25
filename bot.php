<?php
// Load env vars (set in Render)
$TELEGRAM_TOKEN = $_ENV['TELEGRAM_TOKEN'] ?? '';
$COPYLEAKS_KEY = $_ENV['COPYLEAKS_KEY'] ?? '';
$GPTZERO_KEY = $_ENV['GPTZERO_KEY'] ?? '';

if (empty($TELEGRAM_TOKEN)) {
    die("Error: TELEGRAM_TOKEN not set!\n");
}

$offset = 0;
echo "Bot starting...\n";

while (true) {
    $url = "https://api.telegram.org/bot$TELEGRAM_TOKEN/getUpdates?offset=$offset&timeout=30";
    $updates = @file_get_contents($url);
    if ($updates === false) {
        sleep(5); // Retry on error
        continue;
    }
    
    $data = json_decode($updates, true);
    if (!isset($data['ok']) || empty($data['result'])) {
        sleep(1);
        continue;
    }
    
    foreach ($data['result'] as $update) {
        $offset = $update['update_id'] + 1;
        if (!isset($update['message']['text'])) continue;
        
        $chat_id = $update['message']['chat']['id'];
        $text = $update['message']['text'];
        
        if (strpos($text, '/start') === 0) {
            sendMessage($chat_id, "Hi! Send text to check for plagiarism & AI content.");
            continue;
        }
        
        if (strlen($text) < 15) {
            sendMessage($chat_id, "Text too short! Send more.");
            continue;
        }
        
        sendMessage($chat_id, "Checking...");
        
        // Plagiarism check
        $plag = checkPlagiarism($text);
        
        // AI check
        $ai = checkAI($text);
        
        sendMessage($chat_id, "$plag\n\n$ai");
    }
}

function sendMessage($chat_id, $text) {
    global $TELEGRAM_TOKEN;
    $url = "https://api.telegram.org/bot$TELEGRAM_TOKEN/sendMessage?chat_id=$chat_id&text=" . urlencode($text);
    @file_get_contents($url);
}

function checkPlagiarism($text) {
    global $COPYLEAKS_KEY;
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
        return "Plagiarism score: " . ($score * 100) . "%";
    }
    // Basic fallback
    $dummy = "Sample web text.";
    similar_text($text, $dummy, $percent);
    return "Basic plagiarism: " . number_format($percent, 1) . "% (add Copyleaks key for real scans)";
}

function checkAI($text) {
    global $GPTZERO_KEY;
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
        return "AI chance: " . number_format($prob * 100, 1) . "%";
    }
    // Basic fallback
    $words = str_word_count($text);
    $simple_ai_score = min(50, $words / 10); // Dummy logic
    return "Basic AI: " . $simple_ai_score . "% (add GPTZero key for accuracy)";
?>
