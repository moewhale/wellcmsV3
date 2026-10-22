<?php

declare(strict_types=1);

namespace App\Services\System;

class MailService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var array */
    private $appConfig;
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;

    public function __construct(array $appConfig, \Framework\Logger\LoggerInterface $logger)
    {
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * 发送邮件 (支持单发与群发)
     *
     * @param string|array $to 收件人。支持：
     *                         - 字符串: "user@example.com" 或 "user1@a.com,user2@b.com" (逗号/分号分隔)
     *                         - 索引数组: ["user1@a.com", "user2@b.com"]
     *                         - 关联数组: ["user1@a.com" => "Name1", "user2@b.com" => "Name2"]
     * @param string $subject 主题
     * @param string $body 正文 (HTML)
     * @param array $options 扩展选项:
     *                        - 'batch_mode': 'separate' (独立发送，默认，防垃圾) 或 'bcc' (密送)
     *                        - 'cc': 抄送地址 (数组或逗号分隔字符串)
     *                        - 'reply_to': 回复地址
     *                        - 'plain_text': 纯文本正文 (如果不提供则根据 body 自动提取)
     *                        - 'message_id': 本次耐久邮件的固定 Message-ID
     *                        - 'timeout_seconds': 显式单次 SMTP 操作超时，1..60；不传保持原 15 秒
     *                        - 'redact_recipients': 不把收件地址写入错误诊断
     * @return array{status: string, message: string, detail: array}
     */
    public function send($to, string $subject, string $body, array $options = ['batch_mode' => 'separate']): array
    {
        $recipientSecrets = [];
        try {
            if (isset($options['message_id']) && (!is_string($options['message_id']) || !preg_match('/\A<[A-Za-z0-9._-]{1,128}@[A-Za-z0-9.-]{1,253}>\z/D', $options['message_id']))
                || isset($options['timeout_seconds']) && (!is_int($options['timeout_seconds']) || $options['timeout_seconds'] < 1 || $options['timeout_seconds'] > 60)) {
                return ['status' => 'error', 'message' => 'configuration_error', 'detail' => []];
            }
            // 1. 加载 SMTP 配置
            $smtplist = include \App\Core\Compile::include(APP_PATH . 'config/Smtp.php');
            if (empty($smtplist)) {
                return ['status' => 'error', 'message' => 'mailbox_not_configured', 'detail' => []];
            }
            if (!is_array($smtplist)) {
                return ['status' => 'error', 'message' => 'configuration_error', 'detail' => []];
            }
            $recipients = $this->parseRecipients($to);
            if (($options['redact_recipients'] ?? false) === true) $recipientSecrets = array_keys($recipients);
            if (empty($recipients)) {
                return ['status' => 'error', 'message' => 'empty_recipients', 'detail' => []];
            }

            // 随机起点保留负载分配；只在投递前切换服务器，避免 DATA 结果未知时重复投递。
            $serverIds = array_keys($smtplist);
            shuffle($serverIds);
            $mail = null;
            $smtp = [];
            $serverId = null;
            $failureMessage = 'configuration_error';
            foreach ($serverIds as $candidateId) {
                $candidate = $smtplist[$candidateId];
                $candidateMail = null;
                $stage = 'configuration';
                try {
                    if (!is_array($candidate) || empty($candidate['host']) || empty($candidate['port'])
                        || empty($candidate['username']) || !isset($candidate['password']) || $candidate['password'] === '') {
                        throw new \InvalidArgumentException('Incomplete SMTP configuration');
                    }
                    $fromEmail = trim((string)($candidate['email'] ?? $candidate['username']));
                    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
                        || !in_array($candidate['encryption'] ?? 'auto', ['auto', 'ssl', 'tls'], true)
                        || !preg_match('/\A[A-Za-z0-9_-]+\z/', (string)$candidate['password'])
                        || preg_match('/[&<>"\']/', (string)$candidate['username'])
                        || (!empty($candidate['dkim_passphrase']) && !preg_match('/\A[A-Za-z0-9_-]+\z/', (string)$candidate['dkim_passphrase']))
                        || (int)$candidate['port'] < 1 || (int)$candidate['port'] > 65535) {
                        throw new \InvalidArgumentException('Invalid SMTP configuration');
                    }
                    $candidateMail = $this->initMailer($candidate);
                    if (isset($options['message_id'])) $candidateMail->MessageID = $options['message_id'];
                    if (isset($options['timeout_seconds'])) {
                        $candidateMail->Timeout = $options['timeout_seconds'];
                        $candidateMail->getSMTPInstance()->Timelimit = $options['timeout_seconds'];
                    }
                    $candidateMail->Subject = $subject;
                    $candidateMail->Body = $body;
                    $candidateMail->AltBody = $options['plain_text'] ?? strip_tags(str_replace(['<br>', '<br/>', '</p>'], ["\n", "\n", "\n\n"], $body));
                    if (!empty($options['reply_to'])) {
                        $candidateMail->addReplyTo($options['reply_to']);
                    }
                    // 发件身份必须先确定，DKIM identity 才能与真实 From 一致。
                    $candidateMail->setFrom($fromEmail, $this->appConfig['sitename'] ?? 'WellCMS');
                    $candidateMail->Sender = $fromEmail;
                    $this->applyDKIM($candidateMail, $candidate);
                    $candidateMail->SMTPKeepAlive = count($recipients) > 1;
                    $stage = 'connect';
                    if (!$candidateMail->smtpConnect()) {
                        throw new \RuntimeException('SMTP connection failed');
                    }
                    $mail = $candidateMail;
                    $smtp = $candidate;
                    $serverId = $candidateId;
                    break;
                } catch (\Throwable $e) {
                    $candidateMessage = $this->recordFailure($candidateMail, is_array($candidate) ? $candidate : [], $candidateId, $stage, $e, $recipientSecrets);
                    if ($stage !== 'configuration' || $failureMessage === 'configuration_error') {
                        $failureMessage = $candidateMessage;
                    }
                    if ($candidateMail !== null) {
                        $candidateMail->getSMTPInstance()->close();
                    }
                }
            }
            if ($mail === null) {
                return ['status' => 'error', 'message' => $failureMessage, 'detail' => []];
            }

            $batchMode = $options['batch_mode'] ?? 'separate';
            $results = [];
            $successCount = 0;
            try {
                if ($batchMode === 'bcc' && count($recipients) > 1) {
                    try {
                        foreach ($recipients as $email => $name) {
                            $mail->addBCC($email, $name);
                        }
                        $mail->addAddress($mail->From, 'Undisclosed-recipients');
                        if (!$mail->send()) {
                            throw new \RuntimeException('SMTP send failed');
                        }
                        $results['all'] = true;
                        $successCount = 1;
                    } catch (\Throwable $e) {
                        $failureMessage = $this->recordFailure($mail, $smtp, $serverId, 'send', $e, $recipientSecrets);
                        $results['all'] = $failureMessage;
                        $mail->getSMTPInstance()->close();
                    }
                } else {
                    foreach ($recipients as $email => $name) {
                        try {
                            $mail->clearAddresses();
                            $mail->clearCCs();
                            $mail->addAddress($email, $name);
                            if (!empty($options['cc'])) {
                                $this->addAddressesToMailer($mail, 'cc', $options['cc']);
                            }
                            if ($successCount > 0 && !empty($options['jitter'])) {
                                usleep(random_int(200000, 800000));
                            }
                            if (!$mail->send()) {
                                throw new \RuntimeException('SMTP send failed');
                            }
                            $successCount++;
                            $results[$email] = true;
                        } catch (\Throwable $e) {
                            $failureMessage = $this->recordFailure($mail, $smtp, $serverId, 'send', $e, $recipientSecrets);
                            $results[$email] = $failureMessage;
                            // 下一位收件人由 PHPMailer 完整重连、协商和认证，不重投本次失败邮件。
                            $mail->getSMTPInstance()->close();
                        }
                    }
                }
            } finally {
                try {
                    $mail->smtpClose();
                } catch (\Throwable $e) {
                    $this->recordFailure($mail, $smtp, $serverId, 'close', $e, $recipientSecrets);
                    $mail->getSMTPInstance()->close();
                }
            }

            return [
                'status' => $successCount > 0 ? 'success' : 'error',
                'message' => $successCount > 0 ? 'send_success' : $failureMessage,
                'detail' => $results,
            ];
        } catch (\Throwable $e) {
            $this->recordFailure(null, [], null, 'load', $e, $recipientSecrets);
            return ['status' => 'error', 'message' => 'mail_send_failed', 'detail' => []];
        }
    }

    protected function initMailer(array $smtp): \App\PHPMailer\PHPMailer
    {
        $mail = new \App\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'utf-8';
        $mail->Encoding = 'base64'; // 使用 Base64 编码，避免特殊字符导致的评分降低
        $mail->SMTPDebug = 0;
        $mail->isSMTP();

        // 基础配置
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $encryption = $smtp['encryption'] ?? 'auto';
        $defaultEncryption = in_array((int)$smtp['port'], [25, 587, 2587], true) ? 'tls' : 'ssl';
        $mail->SMTPSecure = $encryption === 'auto' ? $defaultEncryption : $encryption;
        $mail->Port = $smtp['port'];
        $mail->SMTPAutoTLS = false;
        $mail->Timeout = 15;

        // 抗垃圾邮件 (Anti-Spam) Header 优化
        $mail->XMailer = 'WellCMS Mailer';
        $mail->Priority = 3;

        // 尝试从应用配置中提取 Hostname，有助于通过 HELO/EHLO 检查
        if (!empty($this->appConfig['domain'])) {
            $host = parse_url($this->appConfig['domain'], PHP_URL_HOST);
            if ($host) $mail->Hostname = $host;
        }

        $mail->isHTML(true);

        return $mail;
    }

    private function recordFailure(?\App\PHPMailer\PHPMailer $mail, array $smtp, $serverId, string $stage, \Throwable $e, array $recipientSecrets): string
    {
        $transport = $mail !== null ? $mail->getSMTPInstance() : null;
        $diagnostics = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'mailer_error' => $mail !== null ? $mail->ErrorInfo : '',
            'smtp_error' => $transport !== null ? $transport->getError() : [],
            'last_reply' => $transport !== null ? $transport->getLastReply() : '',
        ];
        $message = 'mail_send_failed';
        if ($stage === 'configuration') {
            $message = 'configuration_error';
        } elseif ($stage === 'connect') {
            $authenticationFailed = preg_match('/authenticat|AUTH command|^53[458][ -]/im', $e->getMessage() . "\n" . $diagnostics['last_reply']);
            $message = $authenticationFailed ? 'mail_authentication_failed' : 'mail_connection_failed';
        }
        // 仅记录错误快照；不记录正文，并遮蔽凭据及 AUTH 常用编码。
        $secrets = $recipientSecrets;
        foreach (['username', 'password', 'dkim_passphrase'] as $key) {
            if (isset($smtp[$key]) && $smtp[$key] !== '') {
                $secrets[] = (string)$smtp[$key];
                $secrets[] = base64_encode((string)$smtp[$key]);
            }
        }
        if (isset($smtp['username'], $smtp['password'])) {
            $secrets[] = base64_encode("\0" . $smtp['username'] . "\0" . $smtp['password']);
        }
        array_walk_recursive($diagnostics, static function (&$value) use ($secrets): void {
            if (is_string($value)) {
                $value = str_replace($secrets, '[redacted]', $value);
            }
        });
        $this->logger->error('MailService SMTP failure', [
            'stage' => $stage,
            'smtp_index' => $serverId,
            'host' => str_replace($secrets, '[redacted]', (string)($smtp['host'] ?? '')),
            'port' => $smtp['port'] ?? null,
            'diagnostics' => $diagnostics,
        ]);
        return $message;
    }

    /**
     * 应用 DKIM 签名
     * 需在 smtp.config.php 的 server 配置中添加:
     * 'dkim_domain' => 'example.com',
     * 'dkim_private' => '/path/to/private.key',
     * 'dkim_selector' => 'default',
     */
    private function applyDKIM(\App\PHPMailer\PHPMailer $mail, array $smtp): void
    {
        if (!empty($smtp['dkim_domain']) && !empty($smtp['dkim_private']) && file_exists($smtp['dkim_private'])) {
            $mail->DKIM_domain = $smtp['dkim_domain'];
            $mail->DKIM_private = $smtp['dkim_private'];
            $mail->DKIM_selector = $smtp['dkim_selector'] ?? 'default';
            $mail->DKIM_passphrase = $smtp['dkim_passphrase'] ?? '';
            $mail->DKIM_identity = $mail->From;
        }
    }

    /**
     * 解析各种格式的收件人为统一格式: [email => name]
     */
    private function parseRecipients($to): array
    {
        $recipients = [];
        if (is_string($to)) {
            // 支持 "email1,email2" 或 "email1;email2"
            $to = preg_split('/[,;]/', $to);
        }

        if (is_array($to)) {
            foreach ($to as $key => $val) {
                if (is_numeric($key)) {
                    // 索引数组: [email1, email2]
                    $email = trim((string)$val);
                    if ($email) $recipients[$email] = '';
                } else {
                    // 关联数组: [email => name]
                    $email = trim((string)$key);
                    if ($email) $recipients[$email] = trim((string)$val);
                }
            }
        }
        return $recipients;
    }

    /**
     * 辅助方法：向 Mailer 批量添加地址 (CC/BCC)
     */
    private function addAddressesToMailer(\App\PHPMailer\PHPMailer $mail, string $type, $addresses): void
    {
        $parsed = $this->parseRecipients($addresses);
        foreach ($parsed as $email => $name) {
            if ($type === 'cc') {
                $mail->addCC($email, $name);
            } elseif ($type === 'bcc') {
                $mail->addBCC($email, $name);
            }
        }
    }
}

/**
 * MailService 使用指南与示例
 *
 * --- 场景 1: 基础单发 (验证码、系统通知) ---
 * $mailService->send('user@domain.com', '验证码', '您的验证码是 123456');
 *
 * --- 场景 2: 带姓名的单发 ---
 * $mailService->send(['user@domain.com' => '张三'], '欢迎', '<h1>欢迎加入</h1>');
 *
 * --- 场景 3: 工业级群发 (独立发送模式，抗垃圾推荐) ---
 * // 这种模式会为每个收件人生成独立邮件，送达率最高
 * $recipients = [
 *     'a@domain.com' => '用户A',
 *     'b@domain.com', // 也可以不填姓名
 *     'c@domain.com' => '用户C'
 * ];
 * $result = $mailService->send($recipients, '批量通知', '内容', [
 *     'batch_mode' => 'separate', // 默认即为 separate
 *     'jitter' => true,            // 开启随机抖动延迟，防止突发流量触发服务商封禁
 * ]);
 *
 * --- 场景 4: 快速群发 (BCC 密送模式) ---
 * // 仅推荐在内部通知或收件人极少的情况下使用
 * $mailService->send($recipients, '会议提醒', '内容', ['batch_mode' => 'bcc']);
 *
 * --- 场景 5: 高级配置 (CC、回复、纯文本) ---
 * $mailService->send('to@a.com', '复杂邮件', 'HTML内容', [
 *     'cc' => 'manager@a.com',      // 抄送
 *     'reply_to' => 'help@a.com',   // 设置回复地址（有助于提高评分）
 *     'plain_text' => '这是纯文本摘要', // 覆盖自动生成的 AltBody
 * ]);
 *
 * --- 核心反垃圾邮件 (Anti-Spam) 说明 ---
 * 1. DKIM 签名:
 *    请在 config/smtp.config.php 的服务器配置项中添加:
 *    'dkim_domain' => 'yourdomain.com',
 *    'dkim_private' => '/path/to/dkim_private.key',
 *    'dkim_selector' => 'default',
 *    系统会自动识别并为每一封邮件进行高强度加签。
 *
 * 2. 自动 AltBody:
 *    系统会自动转换 HTML 到纯文本，满足反垃圾扫描器的“双格式”检查。
 *
 * 3. 动态 Hostname:
 *    自动关联 AppConfig 中的域名作为 HELO 身份，通过 ISP 完整性检查。
 *
 * 4. SMTP 保持连接:
 *    群发时自动启用 SMTPKeepAlive，维持长连接，表现更像真实的邮件服务器而非脚本。
 */
