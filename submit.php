<?php
declare(strict_types=1);

$wantsJson = wantsJsonResponse();

if (!$wantsJson) {
    header('X-Content-Type-Options: nosniff');
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed', 405, $wantsJson);
}

$configPath = __DIR__ . '/crm-config.php';
if (!is_file($configPath)) {
    respond(false, 'CRM configuration is missing. Create crm-config.php with your LeadPluss settings', 500, $wantsJson);
}

/** @var array<string, mixed> $config */
$config = require $configPath;

$data = $_POST;
if ($data === []) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (!is_array($data) || $data === []) {
    respond(false, 'Invalid request payload', 400, $wantsJson);
}

if (!empty($data['honeypot'])) {
    respond(true, 'Ok', 200, $wantsJson, ['statusCode' => 'Ok']);
}

$vendorKey = trim((string) ($config['vendor_key'] ?? ''));
if ($vendorKey === '' || $vendorKey === 'YOUR_VENDOR_API_KEY') {
    respond(false, 'CRM vendor key is not configured in crm-config.php', 500, $wantsJson);
}

$fullName = trim((string) ($data['firstName'] ?? $data['name'] ?? ''));
$phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));

if ($fullName === '' || strlen($phone) < 10) {
    respond(false, 'Name and valid phone are required', 400, $wantsJson);
}

$nameParts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$firstName = $nameParts[0] ?? $fullName;
$lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : null;

$isd = trim((string) ($data['isd'] ?? ''));
if ($isd === '') {
    $isd = '+91';
}

$leadSource = trim((string) ($data['source'] ?? $config['lead_source'] ?? 'Microsite'));
$project = trim((string) ($data['projectName'] ?? $config['project'] ?? 'Leafsong Retreat'));
if ($project === '') {
    $project = (string) ($config['project'] ?? 'Leafsong Retreat');
}

$messageParts = array_filter([
    trim((string) ($data['enquiryType'] ?? '')),
    trim((string) ($data['sourceCampaign'] ?? '')),
    trim((string) ($data['requirementType'] ?? '')),
    trim((string) ($data['marketingTeam'] ?? '')),
    trim((string) ($data['referralURL'] ?? '')),
    trim((string) ($data['mx_UTM_Source'] ?? '')),
    trim((string) ($data['sourceMedium'] ?? '')),
    trim((string) ($data['mx_UTM_Term'] ?? '')),
    trim((string) ($data['SourceContent'] ?? '')),
    trim((string) ($data['x_UTM_Placement'] ?? '')),
    trim((string) ($data['mx_gclid'] ?? '')),
    trim((string) ($data['physicalLocation'] ?? '')),
    trim((string) ($data['companyName'] ?? '')),
], static fn(string $part): bool => $part !== '');

$payload = [
    'FirstName' => $firstName,
    'LastName' => $lastName,
    'ISD' => $isd,
    'Phone' => $phone,
    'EmailId' => trim((string) ($data['email'] ?? '')) ?: null,
    'State' => trim((string) ($data['state'] ?? '')) ?: null,
    'City' => trim((string) ($data['city'] ?? $config['city'] ?? 'Greater Noida')) ?: null,
    'Location' => trim((string) ($data['location'] ?? $config['location'] ?? 'Sector MU')) ?: null,
    'Project' => $project,
    'Pincode' => trim((string) ($data['pincode'] ?? $config['pincode'] ?? '')) ?: null,
    'PropertyFor' => trim((string) ($data['propertyFor'] ?? $config['property_for'] ?? 'Buy')),
    'Property' => trim((string) ($data['property'] ?? $config['property'] ?? 'Flat')),
    'PropertyType' => trim((string) ($data['propertyType'] ?? $config['property_type'] ?? '2 & 3 BHK')),
    'Message' => $messageParts ? implode(' | ', $messageParts) : 'Website enquiry',
    'LeadSource' => $leadSource,
    'vendor_key' => $vendorKey,
    'budget' => trim((string) ($data['budget'] ?? '')) ?: null,
];

$crmResponse = pushLeadToCrm((string) ($config['endpoint'] ?? ''), $payload);
if ($crmResponse['ok']) {
    respond(true, 'Lead submitted successfully', 200, $wantsJson, [
        'statusCode' => $crmResponse['statusCode'] ?? 'Ok',
    ]);
}

$fallbackSent = sendFallbackEmail($data, $config, $crmResponse['message'] ?? 'CRM push failed');
if ($fallbackSent) {
    respond(true, 'Lead saved via email fallback', 200, $wantsJson, [
        'statusCode' => 'EmailFallback',
    ]);
}

respond(false, $crmResponse['message'] ?? 'Unable to submit lead right now', 502, $wantsJson);

function wantsJsonResponse(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json')) {
        return true;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return str_contains($contentType, 'application/json');
}

/**
 * @param array<string, mixed> $extra
 */
function respond(bool $ok, string $message, int $statusCode, bool $asJson, array $extra = []): void
{
    if ($asJson) {
        http_response_code($statusCode);
        echo json_encode(array_merge([
            'ok' => $ok,
            'message' => $message,
        ], $extra));
        exit;
    }

    if ($ok) {
        header('Location: thank-you.html', true, 303);
        exit;
    }

    http_response_code($statusCode);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Submission Error</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>Unable to submit</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="index.html">Back to Home</a> | <a href="tel:+918384072477">Call 8384072477</a></p></body></html>';
    exit;
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, statusCode?: string, message?: string}
 */
function pushLeadToCrm(string $endpoint, array $payload): array
{
    if ($endpoint === '') {
        return ['ok' => false, 'message' => 'CRM endpoint is not configured'];
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'message' => 'Unable to initialize CRM request'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'message' => $curlError !== '' ? $curlError : 'CRM request failed'];
    }

    $decoded = json_decode($body, true);
    $statusCode = is_array($decoded) ? (string) ($decoded['statusCode'] ?? '') : '';

    if ($httpCode === 200 || $httpCode === 202) {
        return ['ok' => true, 'statusCode' => $statusCode !== '' ? $statusCode : 'Ok'];
    }

    return [
        'ok' => false,
        'message' => $statusCode !== '' ? $statusCode : "CRM returned HTTP $httpCode",
    ];
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $config
 */
function sendFallbackEmail(array $data, array $config, string $crmError): bool
{
    $to = trim((string) ($config['fallback_email'] ?? ''));
    if ($to === '') {
        return false;
    }

    $name = trim((string) ($data['firstName'] ?? $data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $subject = 'Website lead fallback - Leafsong Retreat';
    $lines = [
        'A website lead could not be pushed to LeadPluss.',
        "CRM error: $crmError",
        '',
        "Name: $name",
        "Phone: $phone",
        'Source: ' . ($data['source'] ?? ''),
        'Project: ' . ($data['projectName'] ?? ''),
        'Referral URL: ' . ($data['referralURL'] ?? ''),
        'Enquiry: ' . ($data['enquiryType'] ?? ''),
    ];

    $body = implode(PHP_EOL, $lines);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: noreply@instapropertyhint.com',
    ];

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}
