<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * 邮件验证码 Session 状态机。
 *
 * 服务自身不保存请求状态；Session 由调用方显式传入，兼容 FPM 与 Swoole。
 */
class EmailVerificationService
{
    public const CODE_TTL = 1800;
    public const SEND_COOLDOWN = 60;
    public const MAX_ATTEMPTS = 5;

    /** @var array<string,bool> */
    private $allowedPurposes = [
        'verifyEmail' => true,
        'codeSignIn' => true,
        'resetPassword' => true,
        'originalEmail' => true,
        'modifyEmail' => true,
    ];

    public function isAllowedPurpose(string $purpose): bool
    {
        return isset($this->allowedPurposes[$purpose]);
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function remainingCooldown(
        \Framework\Session\SessionInterface $session,
        string $purpose,
        ?int $now = null
    ): int {
        if (!$this->isAllowedPurpose($purpose)) return 0;

        $data = $session->get($purpose, []);
        if (!is_array($data)) return 0;

        $issuedAt = (int)($data['time'] ?? 0);
        if ($issuedAt <= 0) return 0;

        $now = $now ?? time();
        $elapsed = $now - $issuedAt;
        if ($elapsed < 0) return self::SEND_COOLDOWN;

        return max(0, self::SEND_COOLDOWN - $elapsed);
    }

    public function store(
        \Framework\Session\SessionInterface $session,
        string $purpose,
        string $email,
        string $code,
        ?int $now = null
    ): void {
        if (!$this->isAllowedPurpose($purpose)) {
            throw new \InvalidArgumentException('Unsupported email verification purpose');
        }

        $session->set($purpose, [
            'email' => $this->normalizeEmail($email),
            'code' => strtoupper(trim($code)),
            'time' => $now ?? time(),
            'attempts' => 0,
        ]);
    }

    public function verify(
        \Framework\Session\SessionInterface $session,
        string $purpose,
        string $email,
        string $code,
        ?int $now = null
    ): bool {
        if (!$this->isAllowedPurpose($purpose)) return false;

        $data = $session->get($purpose, []);
        if (!is_array($data)) return false;

        $issuedAt = (int)($data['time'] ?? 0);
        $now = $now ?? time();
        if ($issuedAt <= 0 || $issuedAt > $now || $now - $issuedAt > self::CODE_TTL) {
            $session->delete($purpose);
            return false;
        }

        $expectedEmail = isset($data['email']) ? $this->normalizeEmail((string)$data['email']) : '';
        $expectedCode = isset($data['code']) ? strtoupper(trim((string)$data['code'])) : '';
        $actualEmail = $this->normalizeEmail($email);
        $actualCode = strtoupper(trim($code));

        $matches = $expectedEmail !== ''
            && $expectedCode !== ''
            && hash_equals($expectedEmail, $actualEmail)
            && hash_equals($expectedCode, $actualCode);
        if ($matches) return true;

        $attempts = (int)($data['attempts'] ?? 0) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $session->delete($purpose);
        } else {
            $data['attempts'] = $attempts;
            $session->set($purpose, $data);
        }

        return false;
    }

    public function consume(\Framework\Session\SessionInterface $session, string $purpose): void
    {
        if ($this->isAllowedPurpose($purpose)) $session->delete($purpose);
    }

    public function storeResetAuthorization(
        \Framework\Session\SessionInterface $session,
        int $userId,
        ?int $now = null
    ): void {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Reset authorization requires a user ID');
        }

        $session->set('reset_verified_user_id', [
            'user_id' => $userId,
            'time' => $now ?? time(),
        ]);
    }

    public function resetAuthorizedUserId(
        \Framework\Session\SessionInterface $session,
        ?int $now = null
    ): int {
        $data = $session->get('reset_verified_user_id', []);
        if (!is_array($data)) {
            $session->delete('reset_verified_user_id');
            return 0;
        }

        $userId = (int)($data['user_id'] ?? 0);
        $issuedAt = (int)($data['time'] ?? 0);
        $now = $now ?? time();
        if ($userId <= 0 || $issuedAt <= 0 || $issuedAt > $now || $now - $issuedAt > self::CODE_TTL) {
            $session->delete('reset_verified_user_id');
            return 0;
        }

        return $userId;
    }

    public function clearResetAuthorization(\Framework\Session\SessionInterface $session): void
    {
        $session->delete('reset_verified_user_id');
    }
}
