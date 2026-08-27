<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * การยืนยันตัวตนผ่าน MEDSCI ACC SSO — ดูรายละเอียด/เหตุผลของทุก design decision ใน spec.md ข้อ 3
 *
 * หลักการสำคัญที่ต้องคงไว้เสมอเวลาแก้ไฟล์นี้:
 *   - ไม่มีการเก็บรหัสผ่านผู้ใช้ที่ไหนเลยในระบบ BPM
 *   - $_SESSION เก็บแค่ user_id (+ csrf/sso_state ชั่วคราว) — ห้าม cache role/department ยาวๆ
 *     ต้อง query จาก DB สดทุก request ผ่าน bpm_current_user() เท่านั้น (ดูเหตุผลใน spec.md ข้อ 9)
 */

// ============================================================================
// 1) SSO Login redirect (public/login.php เรียกใช้)
// ============================================================================

/** สุ่ม state ใหม่ เก็บใน session แล้วคืน URL เต็มสำหรับ redirect ไป MEDSCI ACC login */
function bpm_sso_login_redirect_url(): string
{
    $sso = bpm_config()['sso'];

    $state = bin2hex(random_bytes(16));
    $_SESSION['sso_state'] = $state;

    return $sso['login_url'] . '?' . http_build_query([
        'client_id'    => $sso['client_id'],
        'redirect_uri' => $sso['redirect_uri'],
        'state'        => $state,
    ]);
}

// ============================================================================
// 2) SSO Callback (public/sso_callback.php เรียกใช้)
// ============================================================================

/**
 * ตรวจ state ที่ MEDSCI ACC ส่งกลับมาเทียบกับที่เก็บไว้ใน session
 * ต้องเรียกก่อนทำอย่างอื่นทั้งหมดใน sso_callback.php เสมอ (ดู spec.md ข้อ 3.3/3.6)
 * ใช้แล้วลบทิ้งทันทีไม่ว่าผลจะเป็นอย่างไร กันเอาไปใช้ซ้ำ
 */
function bpm_sso_validate_and_consume_state(?string $incomingState): bool
{
    $savedState = $_SESSION['sso_state'] ?? '';
    unset($_SESSION['sso_state']);

    if (empty($incomingState) || empty($savedState)) {
        return false;
    }

    return hash_equals($savedState, $incomingState);
}

/**
 * ส่ง token ไปตรวจสอบกับ MEDSCI ACC verify API
 * คืนค่าเป็น array เสมอ ไม่ throw — ให้ผู้เรียกตัดสินใจว่าจะ redirect ไป error type ไหน
 *
 * @return array{ok: bool, network_error: bool, message: ?string, user: ?array}
 */
function bpm_sso_verify_token(string $token): array
{
    $sso = bpm_config()['sso'];

    $ch = curl_init($sso['verify_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'token'         => $token,
            'client_id'     => $sso['client_id'],
            'client_secret' => $sso['client_secret'],
        ]),
        // ต้องเป็น true บน production เสมอ (ดู spec.md ข้อ 3.6) — false ใช้ได้เฉพาะ dev ที่ cert มีปัญหาจริงๆ
        CURLOPT_SSL_VERIFYPEER => bpm_config()['sso_ssl_verify'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $responseJson = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // เชื่อมต่อ MEDSCI ACC ไม่ได้เลย — คนละกรณีกับ token ไม่ผ่าน (ดู error type ในข้อ 3.8)
    if ($curlErrno !== 0) {
        error_log('[BPM SSO] verify unreachable: ' . $curlError);
        return ['ok' => false, 'network_error' => true, 'message' => null, 'user' => null];
    }

    $result = json_decode((string) $responseJson, true);

    if (is_array($result) && ($result['status'] ?? null) === 'success' && isset($result['user'])) {
        return ['ok' => true, 'network_error' => false, 'message' => null, 'user' => $result['user']];
    }

    // token ผิด/หมดอายุ/ใช้ซ้ำ/ไม่ใช่บุคลากรคณะ — แยกไม่ได้จาก response (API ไม่มี error code แยก) ใช้ message ตรงๆ
    return [
        'ok'            => false,
        'network_error' => false,
        'message'       => $result['message'] ?? null,
        'user'          => null,
    ];
}

/**
 * จับคู่/สร้างผู้ใช้ local จากข้อมูลโปรไฟล์ที่ MEDSCI ACC ยืนยันแล้ว (ดู spec.md ข้อ 3.4 — 5 ขั้นตอน)
 * เรียกเฉพาะหลัง bpm_sso_verify_token() คืน ok=true เท่านั้น
 *
 * @param array $ssoUser ['user_id'=>.., 'username'=>.., 'name'=>.., 'pos_name'=>.., 'div_name'=>.., 'email'=>..]
 * @return array แถวผู้ใช้ local ปัจจุบัน (หลัง insert/update แล้ว)
 */
function bpm_sso_provision_user(array $ssoUser): array
{
    $db = bpm_db();
    $ssoUsername = (string) $ssoUser['username'];

    $stmt = $db->prepare('SELECT * FROM users WHERE sso_username = ?');
    $stmt->execute([$ssoUsername]);
    $existing = $stmt->fetch();

    if ($existing) {
        // พบแล้ว (ไม่ว่าจะมี role หรือยัง) — sync ชื่อ/อีเมลล่าสุดจาก SSO เผื่อเปลี่ยน แล้วอัปเดตเวลา login
        $update = $db->prepare(
            'UPDATE users SET name = ?, email = ?, pos_name = ?, div_name = ?, last_login_at = NOW() WHERE id = ?'
        );
        $update->execute([
            $ssoUser['name'],
            $ssoUser['email'],
            $ssoUser['pos_name'] ?? null,
            $ssoUser['div_name'] ?? null,
            $existing['id'],
        ]);

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$existing['id']]);
        return $stmt->fetch();
    }

    // ไม่พบ — สร้างใหม่ role = NULL เสมอ (ADMIN ต้องมากำหนดสิทธิ์เองทีหลัง ดูเหตุผลในข้อ 3.4/2)
    $insert = $db->prepare(
        'INSERT INTO users (sso_username, sso_user_id, name, email, pos_name, div_name, role, last_login_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())'
    );
    $insert->execute([
        $ssoUsername,
        $ssoUser['user_id'] ?? null,
        $ssoUser['name'],
        $ssoUser['email'],
        $ssoUser['pos_name'] ?? null,
        $ssoUser['div_name'] ?? null,
    ]);

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $db->lastInsertId()]);
    return $stmt->fetch();
}

/**
 * เริ่ม authenticated session หลัง provisioning สำเร็จ
 * session_regenerate_id ต้องมาก่อนเซ็ตค่าอื่นเสมอ (ป้องกัน session fixation — ดู spec.md ข้อ 3.3)
 */
function bpm_start_authenticated_session(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();
}

// ============================================================================
// 3) Current user / guards (ทุกหน้าที่ต้อง login เรียกใช้)
// ============================================================================

/**
 * คืนข้อมูลผู้ใช้ปัจจุบันแบบสดจาก DB เสมอ (memoize แค่ภายใน request เดียว ไม่ข้าม request)
 * คืน null ถ้ายังไม่ login หรือบัญชีถูกระงับ (is_active = 0)
 */
function bpm_current_user(): ?array
{
    static $cached = false; // false = ยังไม่เคย query รอบนี้, null/array = query แล้ว

    if ($cached !== false) {
        return $cached;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null) {
        return $cached = null;
    }

    $stmt = bpm_db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $cached = ($user ?: null);
}

/** เด้งไป login.php ถ้ายังไม่ login — เรียกตอนต้นของทุกหน้าที่ต้อง login ก่อนใช้งาน */
function bpm_require_login(): array
{
    bpm_enforce_session_idle_timeout();

    $user = bpm_current_user();
    if ($user === null) {
        header('Location: ' . bpm_url('login.php'));
        exit;
    }

    return $user;
}

/**
 * เหมือน bpm_require_login() แต่เช็ค role ด้วย — role ที่ยังเป็น NULL จะเด้งไปหน้ารอสิทธิ์เสมอ
 * ไม่ว่า $roles ที่ระบุจะเป็นอะไรก็ตาม (กันบัญชี SSO ใหม่ที่ยังไม่ถูกกำหนดสิทธิ์ — ดู spec.md ข้อ 9)
 */
function bpm_require_role(string ...$roles): array
{
    $user = bpm_require_login();

    if ($user['role'] === null) {
        header('Location: ' . bpm_url('pending-access.php'));
        exit;
    }

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }

    return $user;
}

/**
 * Session idle timeout (ดู spec.md ข้อ 9) — เรียกจาก bpm_require_login() เสมอ
 * ห้ามพึ่ง session.gc_maxlifetime ของ PHP อย่างเดียวเพราะเป็นแค่ garbage collection ไม่ใช่ enforcement
 */
function bpm_enforce_session_idle_timeout(): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $timeout = bpm_config()['session']['idle_timeout_seconds'];
    $lastActivity = $_SESSION['last_activity'] ?? 0;

    if (time() - $lastActivity > $timeout) {
        session_destroy();
        header('Location: ' . bpm_url('login.php?reason=idle_timeout'));
        exit;
    }

    $_SESSION['last_activity'] = time();
}

// ============================================================================
// 4) Logout — SLO ของ MEDSCI ACC เป็น GET redirect ธรรมดา ไม่ใช่ cURL (ดู spec.md ข้อ 3.7)
// ============================================================================

function bpm_sso_logout_redirect_url(): string
{
    $sso = bpm_config()['sso'];

    // login.php ของ BPM เอง — ให้ MEDSCI ACC ส่งผู้ใช้กลับมาที่นี่หลังเคลียร์ session กลางเสร็จ
    $loginUrl = rtrim(dirname($sso['redirect_uri']), '/') . '/login.php';

    return $sso['logout_url'] . '?' . http_build_query([
        'redirect_uri' => $loginUrl,
        'client_id'    => $sso['client_id'],
    ]);
}

// ============================================================================
// 5) CSRF token สำหรับฟอร์ม POST ทั้งหมด (ดู spec.md ข้อ 9)
// ============================================================================

function bpm_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function bpm_csrf_verify(?string $token): bool
{
    return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/** ใส่ใน <form> ทุกอันที่มีผลต่อข้อมูล: <?= bpm_csrf_field() ?> */
function bpm_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(bpm_csrf_token()) . '">';
}

// ============================================================================
// 6) Flash message ข้ามหน้า (แสดงครั้งเดียวแล้วหาย) — ใช้กับ redirect หลัง POST เสมอ (PRG pattern)
// ============================================================================

function bpm_flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** อ่านแล้วลบทิ้งทันที — เรียกได้แค่ครั้งเดียวต่อรอบ redirect */
function bpm_flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}
