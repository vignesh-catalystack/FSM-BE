<?php
require '../config/db.php';
require '../middleware/auth.php';

header('Content-Type: application/json');

try {
    // ✅ AUTH USER (REMOVE HARDCODE)
    $user = authenticate($pdo);
    $userId = (int)$user['id'];

    $data = json_decode(file_get_contents("php://input"), true);

    $fcmToken = $data['fcm_token'] ?? null;

    if (!$fcmToken || strlen($fcmToken) < 50) {
        http_response_code(400);
        echo json_encode(["message" => "Invalid FCM token"]);
        exit;
    }

    // 🔥 DEBUG LOG
    error_log("📥 RECEIVED TOKEN: " . $fcmToken);
    error_log("👤 USER ID: " . $userId);

    // ✅ SAVE TOKEN (NO TRIM / NO MODIFICATION)
    $stmt = $pdo->prepare("
        UPDATE users 
        SET fcm_token = ? 
        WHERE id = ?
    ");

    $stmt->execute([$fcmToken, $userId]);

    // 🔥 VERIFY SAVE
    $checkStmt = $pdo->prepare("SELECT fcm_token FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    $savedToken = $checkStmt->fetchColumn();

    error_log("💾 SAVED TOKEN: " . $savedToken);

    echo json_encode([
        "message" => "FCM token saved",
        "user_id" => $userId
    ]);

} catch (Throwable $e) {
    error_log("❌ SAVE TOKEN ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        "message" => "Failed to save token"
    ]);
}