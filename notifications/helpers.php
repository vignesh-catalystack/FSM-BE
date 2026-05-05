<?php

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * 🔥 Get Firebase OAuth Access Token (HTTP v1)
 */
function getAccessToken() {
    $keyFile = __DIR__ . '/../config/fsm-57b79-0277e37940ee.json';

    if (!file_exists($keyFile)) {
        throw new Exception("Firebase key file not found");
    }

    $jsonKey = json_decode(file_get_contents($keyFile), true);

    $now = time();

    $header = base64UrlEncode(json_encode([
        "alg" => "RS256",
        "typ" => "JWT"
    ]));

    $claim = base64UrlEncode(json_encode([
        "iss" => $jsonKey["client_email"],
        "scope" => "https://www.googleapis.com/auth/firebase.messaging",
        "aud" => "https://oauth2.googleapis.com/token",
        "exp" => $now + 3600,
        "iat" => $now
    ]));

    $signatureInput = $header . "." . $claim;

    openssl_sign($signatureInput, $signature, $jsonKey["private_key"], "SHA256");

    $jwt = $signatureInput . "." . base64UrlEncode($signature);

    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion" => $jwt
    ]));

    $response = json_decode(curl_exec($ch), true);

    if (curl_errno($ch)) {
        throw new Exception("OAuth CURL ERROR: " . curl_error($ch));
    }

    curl_close($ch);

    if (!isset($response["access_token"])) {
        throw new Exception("Failed to get access token: " . json_encode($response));
    }

    return $response["access_token"];
}

/**
 * 🔔 Send Push Notification (FCM HTTP v1)
 */
function sendPush($token, $title, $message) {

    error_log("🔥 PUSH TRIGGERED");
    error_log("📱 TOKEN USED: " . $token);

    try {
        $accessToken = getAccessToken();
        error_log("✅ ACCESS TOKEN GENERATED");

        $projectId = "fsm-57b79";
        $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

        $data = [
            "message" => [
                "token" => $token,
                "notification" => [
                    "title" => $title,
                    "body" => $message
                ],
                "android" => [
                    "priority" => "HIGH",
                    "notification" => [
                        "sound" => "default"
                    ]
                ]
            ]
        ];

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log("❌ CURL ERROR: " . curl_error($ch));
        }

        error_log("🔥 FCM RESPONSE RAW: " . $response);

        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            error_log("❌ FCM ERROR: " . json_encode($decoded['error']));
        } else {
            error_log("✅ FCM SUCCESS");
        }

        curl_close($ch);

    } catch (Exception $e) {
        error_log("❌ FCM EXCEPTION: " . $e->getMessage());
    }
}

/**
 * 📦 Ensure Notifications Table Exists
 */
function ensureNotificationsTable($pdo) {
    static $ensured = null;

    if ($ensured) return true;

    $existsStmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
    if ($existsStmt && $existsStmt->fetchColumn()) {
        $ensured = true;
        return true;
    }

    if ($pdo->inTransaction()) return false;

    $sql = "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            reference_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $pdo->exec($sql);
    $ensured = true;
    return true;
}

/**
 * 🧠 Create Notification + Trigger Push
 */
function createNotification($pdo, $userId, $title, $body, $type = 'general', $referenceId = null) {

    if (!ensureNotificationsTable($pdo)) return false;

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, body, type, reference_id)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (int)$userId,
        (string)$title,
        (string)$body,
        (string)$type,
        $referenceId !== null ? (int)$referenceId : null
    ]);

    // 🔥 GET USER FCM TOKEN
    $stmt = $pdo->prepare("SELECT fcm_token FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $fcmToken = $stmt->fetchColumn();

    error_log("📦 DB TOKEN: " . $fcmToken);

    // 🔔 SEND PUSH
    if ($fcmToken && strlen($fcmToken) > 50) {
        sendPush($fcmToken, $title, $body);
    } else {
        error_log("❌ INVALID TOKEN FROM DB");
    }

    return true;
}