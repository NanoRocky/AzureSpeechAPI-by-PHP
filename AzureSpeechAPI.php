<?php

// 允许的域名列表
$allowed_origins = [
    // 填写自己的域名以防止被他人调用，想公开 api 可直接删除此模块。
    'https://your.domain'
];

// 检查HTTP_ORIGIN头
if (isset($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    logErrorHeaders('Forbidden: Invalid HTTP_ORIGIN', $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
    respondWithError('Forbidden', 403);
    exit;
}

// 检查HTTP_REFERER头
if (isset($_SERVER['HTTP_REFERER'])) {
    $referer_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
    $allowed_referers = array_map(function($url) {
        return parse_url($url, PHP_URL_HOST);
    }, $allowed_origins);

    if (!in_array($referer_host, $allowed_referers)) {
        logErrorHeaders('Forbidden: Invalid HTTP_REFERER', $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        respondWithError('Forbidden', 403);
        exit;
    }
}

// Azure Speech服务的订阅密钥和区域
$subscriptionKey = '填写你的订阅密钥';
$region = '填写你的订阅区域';

if (!$subscriptionKey || !$region) {
    respondWithError('Azure subscription key or region not set in environment variables.', 500);
    exit;
}

// 获取POST请求中的文本和音色设置（默认值可自行更改）
$text = $_POST['text'] ?? '你好！这是一个文字转语音的API。'; // 没有指定文字内容时返回默认，用于测试
$voice = $_POST['voice'] ?? 'zh-CN-YunxiaNeural'; // 默认使用云夏的声音
$style = $_POST['style'] ?? 'cheerful'; // 默认使用愉悦风格
$role = $_POST['role'] ?? 'Boy'; // 默认模仿男孩音色
$rate = $_POST['rate'] ?? '1'; // 默认语速为正常
$volume = $_POST['volume'] ?? '100'; // 默认音量为满

// 验证输入参数
if (empty($text)) {
    respondWithError('Text is required', 400);
    exit;
}

// 获取访问令牌
$accessToken = getAccessToken($subscriptionKey, $region);
if (!$accessToken) {
    respondWithError('Failed to obtain access token', 500);
    exit;
}

// API请求的URL
$url = "https://$region.tts.speech.microsoft.com/cognitiveservices/v1";

// 请求头
$headers = [
    'Content-Type: application/ssml+xml',
    'X-Microsoft-OutputFormat: riff-48khz-16bit-mono-pcm',
    "Authorization: Bearer $accessToken",
    'User-Agent: PHP-Speech-Service'
];

// 请求体（SSML格式），包括语言、声音和说话风格
$ssml = <<<EOD
<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xmlns:mstts='http://www.w3.org/2001/mstts' xml:lang='en-US'>
  <voice name='$voice'>
    <mstts:express-as style='$style' role='$role'>
        <prosody rate='$rate' volume='$volume'>
          $text
        </prosody>
    </mstts:express-as>
  </voice>
</speak>
EOD;

$headers[] = 'Content-Length: ' . strlen($ssml);

// 初始化cURL会话
$ch = curl_init();

// 设置cURL选项
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $ssml);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 在 Windows 上可能需要设置为 true

// 执行cURL请求
$response = curl_exec($ch);

// 检查cURL错误
if (curl_errno($ch)) {
    error_log('cURL error: ' . curl_error($ch));
    respondWithError('cURL error: ' . curl_error($ch), 500);
    curl_close($ch);
    exit;
}

// 获取HTTP状态码
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 检查API响应状态码
if ($http_status != 200) {
    error_log('API error: HTTP status ' . $http_status . ', response: ' . $response);
    respondWithError('API error: HTTP status ' . $http_status, $http_status);
    exit;
}

// 返回音频数据
header('Content-Type: audio/wav');
echo $response;

// 获取访问令牌的函数
function getAccessToken($subscriptionKey, $region) {
    // 用于保存临时 token 的文件，请自行指定路径。
    $tokenFile = 'yourpath\access_token.json';
    $now = time();
    $tokens = [];

    // 读取令牌文件
    if (file_exists($tokenFile)) {
        $tokens = json_decode(file_get_contents($tokenFile), true) ?? [];
        // 移除过期的令牌
        if (is_array($tokens)) {
            foreach ($tokens as $key => $token) {
                if ($now - $token['timestamp'] >= 480) {
                    unset($tokens[$key]);
                }
            }
        }
    }

    // 检查是否已有当前订阅密钥和区域的有效令牌
    $tokenKey = md5($subscriptionKey . $region);
    if (isset($tokens[$tokenKey])) {
        return $tokens[$tokenKey]['token'];
    }

    $tokenUrl = "https://$region.api.cognitive.microsoft.com/sts/v1.0/issuetoken";
    $headers = [
        "Ocp-Apim-Subscription-Key: $subscriptionKey",
        'Content-Length: 0'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('cURL error (token): ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status != 200) {
        error_log("HTTP status code (token): $http_status , response: $response");
        return null;
    }

    $accessToken = $response;
    $tokens[$tokenKey] = [
        'token' => $accessToken,
        'timestamp' => $now
    ];
    file_put_contents($tokenFile, json_encode($tokens));

    return $accessToken;
}

// 统一错误响应格式的函数
function respondWithError($message, $statusCode = 500) {
    header('Content-Type: application/json', true, $statusCode);
    echo json_encode(['error' => $message]);
}

// 记录错误头信息的函数
function logErrorHeaders($message, $origin, $referer) {
    // 用于存储没有通过验证的 header ，原本是用于调试的，最后..先留着叭！不需要可以直接扔进垃圾桶里面，需要记得指定路径！
    $logFile = 'yourpath\error_header.json';
    $errorData = [
        'message' => $message,
        'origin' => $origin,
        'referer' => $referer,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if (file_exists($logFile)) {
        $existingData = json_decode(file_get_contents($logFile), true);
        if (is_array($existingData)) {
            $existingData[] = $errorData;
        } else {
            $existingData = [$errorData];
        }
        file_put_contents($logFile, json_encode($existingData));
    } else {
        file_put_contents($logFile, json_encode([$errorData]));
    }
}

?>
