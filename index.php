
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
// Configuration
define('BOT_TOKEN', 'XXXXX');
define('OWNER_USER_ID', 'XXXXX'); 
define('CHANNEL_USERNAME', '@XXXXXX');
define('GEMINI_API_KEY', 'XXXXX');



define('GEMINI_MODEL', 'gemini-1.5-flash');




function writeLog($message, $level = 'INFO') {
    $logFile = 'bot.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [$level] $message\n", FILE_APPEND);
}

function saveHistory($userId, $message, $response) {
    $historyFile = "chat_history/{$userId}.json";
    $history = [];
    
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true) ?? [];
    }
    
    $history[] = [
        'role' => 'user',
        'parts' => [['text' => $message]]
    ];
    $history[] = [
        'role' => 'model',
        'parts' => [['text' => $response]]
    ];
    
    if (count($history) > 10) {
        $history = array_slice($history, -10);
    }
    
    if (!is_dir('chat_history')) {
        mkdir('chat_history', 0755, true);
    }
    
    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
}

function getGeminiResponse($userId, $messageText) {
    $url = "https://generativelanguage.googleapis.com/v1/models/" . GEMINI_MODEL . ":generateContent";
    
    $historyFile = "chat_history/{$userId}.json";
    $history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];

    $systemPrompt = "You are a helpful AI assistant. Be direct and concise in your responses. Maintain context from previous messages.";

    $messages = [];
    $messages[] = [
        'role' => 'model',
        'parts' => [['text' => $systemPrompt]]
    ];
    
    foreach ($history as $msg) {
        $messages[] = $msg;
    }
    
    $messages[] = [
        'role' => 'user',
        'parts' => [['text' => $messageText]]
    ];

    $payload = [
        'contents' => $messages,
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1000
        ]
    ];

    $ch = curl_init($url . "?key=" . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    try {
        $response = curl_exec($ch);
        $responseData = json_decode($response, true);
        $responseText = isset($responseData['candidates'][0]['content']['parts'][0]['text']) 
            ? $responseData['candidates'][0]['content']['parts'][0]['text']
            : "Sorry, I couldn't process your message.";
            
        saveHistory($userId, $messageText, $responseText);
        return $responseText;
    } catch (Exception $e) {
        writeLog("Error: " . $e->getMessage(), 'ERROR');
        return "Sorry, I'm having trouble processing your request.";
    } finally {
        curl_close($ch);
    }
}

function handlePhoto($message) {
    $photo = end($message->photo);
    $fileId = $photo->file_id;
    
    $fileInfo = callTelegramAPI('getFile', ['file_id' => $fileId]);
    if (!$fileInfo || !isset($fileInfo['result']['file_path'])) {
        return "Sorry, couldn't process the image.";
    }
    
    $filePath = $fileInfo['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$filePath";
    $imageData = file_get_contents($fileUrl);
    $base64Image = base64_encode($imageData);
    
    $url = "https://generativelanguage.googleapis.com/v1/models/" . GEMINI_MODEL . ":generateContent?key=" . GEMINI_API_KEY;
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => 'image/jpeg',
                            'data' => $base64Image
                        ]
                    ],
                    [
                        'text' => isset($message->caption) ? $message->caption : 'Describe this image'
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1000
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    try {
        $response = curl_exec($ch);
        $responseData = json_decode($response, true);
        $responseText = isset($responseData['candidates'][0]['content']['parts'][0]['text']) 
            ? $responseData['candidates'][0]['content']['parts'][0]['text']
            : "Sorry, I couldn't analyze this image.";
        saveHistory($message->from->id, "[Image with caption: " . ($message->caption ?? "No caption") . "]", $responseText);
        return $responseText;
    } catch (Exception $e) {
        writeLog("Error in vision: " . $e->getMessage(), 'ERROR');
        return "Sorry, I'm having trouble analyzing this image.";
    } finally {
        curl_close($ch);
    }
}

function handleMessage($message, $isBusinessMessage = false) {
    $userId = $message->from->id;
    $chatId = $message->chat->id;
    $messageId = $message->message_id;
    $messageText = trim($message->text ?? '');
    $chatType = $message->chat->type;

    if (empty($messageText)) return;

    if (!$isBusinessMessage && $chatType === 'private' && !checkMembership($userId)) {
        callTelegramAPI('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Please join " . CHANNEL_USERNAME . " to use this bot.",
            'reply_to_message_id' => $messageId
        ]);
        return;
    }

    callTelegramAPI('sendChatAction', [
        'chat_id' => $chatId,
        'action' => 'typing'
    ]);

    $response = getGeminiResponse($userId, $messageText);
    $messageParams = [
        'chat_id' => $chatId,
        'text' => $response,
        'reply_to_message_id' => $messageId
    ];

    if ($isBusinessMessage && isset($message->business_connection_id)) {
        $messageParams['business_connection_id'] = $message->business_connection_id;
    }

    callTelegramAPI('sendMessage', $messageParams);
    writeLog(($isBusinessMessage ? "Business" : "Regular") . " message handled for user $userId");
}

function callTelegramAPI($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data)
        ]
    ];

    try {
        $response = file_get_contents($url, false, stream_context_create($options));
        return $response ? json_decode($response, true) : null;
    } catch (Exception $e) {
        writeLog("API error: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

function checkMembership($userId) {
    $response = callTelegramAPI('getChatMember', [
        'chat_id' => CHANNEL_USERNAME,
        'user_id' => $userId
    ]);

    if ($response && isset($response['result']['status'])) {
        return in_array($response['result']['status'], ['member', 'administrator', 'creator']);
    }
    return false;
}

try {
    $update = json_decode(file_get_contents('php://input'));
    if ($update) {
        if (isset($update->message)) {
            if (isset($update->message->photo)) {
                $response = handlePhoto($update->message);
                callTelegramAPI('sendMessage', [
                    'chat_id' => $update->message->chat->id,
                    'text' => $response,
                    'reply_to_message_id' => $update->message->message_id
                ]);
            } else {
                handleMessage($update->message, false);
            }
        } elseif (isset($update->business_message)) {
            handleMessage($update->business_message, true);
        }
    }
} catch (Exception $e) {
    writeLog("Critical error: " . $e->getMessage(), 'ERROR');
}
