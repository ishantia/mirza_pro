<?php
require_once 'request.php';

header('Content-Type: application/json');

$panelUrl = rtrim((string) ($_GET['panel_url'] ?? ''), '/');
$apiKey = (string) ($_GET['api_key'] ?? '');
$configuration = (string) ($_GET['configuration'] ?? '');

if ($panelUrl === '' || $apiKey === '') {
    echo json_encode([
        'status' => false,
        'msg' => 'panel_url and api_key query parameters are required.',
    ]);
    exit;
}

$headers = [
    'Accept: application/json',
    'wg-dashboard-apikey: ' . $apiKey,
];

function callWgEndpoint(string $label, string $url, array $headers): array
{
    $request = new CurlRequest($url);
    $request->setHeaders($headers);
    $response = $request->get();

    $httpStatus = $response['status'] ?? null;
    $rawBody = $response['body'] ?? '';
    $curlError = $response['error'] ?? null;
    $decodedBody = json_decode((string) $rawBody, true);
    $isJson = json_last_error() === JSON_ERROR_NONE;

    if ($curlError || $httpStatus === 0 || (is_numeric($httpStatus) && $httpStatus >= 400) || !$isJson) {
        error_log(sprintf(
            '%s diagnostic - HTTP: %s, curl_error: %s, json_error: %s, raw: %s',
            $label,
            var_export($httpStatus, true),
            $curlError ?? 'none',
            $isJson ? 'none' : json_last_error_msg(),
            substr((string) $rawBody, 0, 1000)
        ));
    }

    return [
        'label' => $label,
        'url' => $url,
        'http_status' => $httpStatus,
        'curl_error' => $curlError,
        'is_json' => $isJson,
        'body' => $isJson ? $decodedBody : $rawBody,
    ];
}

$results = [
    'handshake' => callWgEndpoint('handshake', $panelUrl . '/api/handshake', $headers),
];

if ($configuration !== '') {
    $results['available_ips'] = callWgEndpoint('getAvailableIPs', $panelUrl . '/api/getAvailableIPs/' . $configuration, $headers);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
