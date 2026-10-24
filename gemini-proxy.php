<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => ['message' => 'Only POST requests are supported.']]);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($originHost === '' || $requestHost === '' || $originHost !== strtolower((string) preg_replace('/:\d+$/', '', $requestHost))) {
        respond(403, ['error' => ['message' => 'Requests must come from this portal.']]);
    }
}

$apiKey = trim((string) getenv('AQ.Ab8RN6ITmCpDXQtEF03VRZIhvvHUy4CNUWRWhVaji9Y_1mXbSQ'));
if ($apiKey === '') {
    respond(503, ['error' => ['message' => 'The portal AI service is not configured yet. Please contact the portal administrator.']]);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'smportal-gemini-' . hash('sha256', $ip) . '.json';
$rateHandle = @fopen($rateFile, 'c+');
if ($rateHandle === false || !flock($rateHandle, LOCK_EX)) {
    if (is_resource($rateHandle)) {
        fclose($rateHandle);
    }
    respond(503, ['error' => ['message' => 'The AI service is temporarily unavailable. Please try again shortly.']]);
}

$now = time();
$rateData = json_decode((string) stream_get_contents($rateHandle), true);
if (!is_array($rateData) || !isset($rateData['start'], $rateData['count']) || $now - (int) $rateData['start'] >= 600) {
    $rateData = ['start' => $now, 'count' => 0];
}
if ((int) $rateData['count'] >= 60) {
    flock($rateHandle, LOCK_UN);
    fclose($rateHandle);
    respond(429, ['error' => ['message' => 'Too many AI requests from this network. Please wait a few minutes and try again.']]);
}
$rateData['count'] = (int) $rateData['count'] + 1;
rewind($rateHandle);
ftruncate($rateHandle, 0);
fwrite($rateHandle, json_encode($rateData));
fflush($rateHandle);
flock($rateHandle, LOCK_UN);
fclose($rateHandle);

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== 0) {
    respond(415, ['error' => ['message' => 'Send a JSON request body.']]);
}

$rawBody = file_get_contents('php://input', false, null, 0, 65537);
if ($rawBody === false || strlen($rawBody) > 65536) {
    respond(413, ['error' => ['message' => 'The request is too large.']]);
}

try {
    $request = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    respond(400, ['error' => ['message' => 'The request body is not valid JSON.']]);
}

$model = (string) ($request['model'] ?? '');
$input = $request['input'] ?? null;
$systemText = $request['system_instruction'] ?? '';

if ($model !== 'gemini-3.8-flash' ||
    !is_string($input) ||
    trim($input) === '' ||
    strlen($input) > 30000 ||
    !is_string($systemText) ||
    trim($systemText) === '' ||
    strlen($systemText) > 5000 ||
    strlen($input) + strlen($systemText) > 30000) {
    respond(400, ['error' => ['message' => 'The AI request has invalid settings or content.']]);
}

if (!function_exists('curl_init')) {
    respond(500, ['error' => ['message' => 'The portal server is missing the PHP cURL extension.']]);
}

$payload = [
    'model' => $model,
    'input' => $input,
    'system_instruction' => $systemText,
    'store' => false
];

$encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
if ($encodedPayload === false) {
    respond(400, ['error' => ['message' => 'The AI request could not be encoded.']]);
}

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => $encodedPayload
]);

$responseBody = curl_exec($curl);
$curlError = curl_errno($curl);
$upstreamStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($responseBody === false || $curlError !== 0) {
    respond(502, ['error' => ['message' => 'The portal could not reach Gemini. Please try again shortly.']]);
}

$upstreamData = json_decode($responseBody, true);
if (!is_array($upstreamData)) {
    respond(502, ['error' => ['message' => 'Gemini returned an unreadable response.']]);
}

if ($upstreamStatus < 200 || $upstreamStatus >= 300) {
    respond($upstreamStatus, $upstreamData);
}

$outputText = trim((string) ($upstreamData['output_text'] ?? ''));
if ($outputText === '') {
    $steps = $upstreamData['output'] ?? $upstreamData['outputs'] ?? [];
    if (is_array($steps)) {
        foreach ($steps as $step) {
            if (!is_array($step) || !in_array($step['type'] ?? '', ['model_output', 'text'], true)) {
                continue;
            }
            if (isset($step['text']) && is_string($step['text'])) {
                $outputText .= $step['text'];
                continue;
            }
            $content = $step['content'] ?? [];
            if (is_array($content)) {
                foreach ($content as $item) {
                    if (is_array($item) && is_string($item['text'] ?? null)) {
                        $outputText .= $item['text'];
                    }
                }
            }
        }
        $outputText = trim($outputText);
    }
}

if ($outputText === '') {
    respond(502, ['error' => ['message' => 'Gemini completed the interaction without a text answer.']]);
}

respond(200, ['output_text' => $outputText]);
