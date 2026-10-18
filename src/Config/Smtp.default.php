<?php

// 临时发信测试模板：请替换下面的占位值，当前配置不能用于真实发信。
// SMTP 登录账号与发件邮箱分别填写，不要求两者相同。
// 密码仅允许英文字母、数字、下划线和连字符，不能包含空格或其他字符。
// 生产模式下手工修改后，测试前需刷新此文件的编译缓存；后台保存会自动刷新。
return [
    [
        'email' => 'sender@example.com',          // 替换为服务商允许的真实发件邮箱
        'host' => 'smtp.example.com',            // 替换为真实 SMTP 服务器地址
        'port' => 465,
        'encryption' => 'ssl',                   // 隐式 TLS；STARTTLS 可改为 587 + tls
        'username' => 'YOUR_SMTP_USERNAME',      // 替换为登录账号，按服务商要求填写
        'password' => 'REPLACE_WITH_TEMP_PASSWORD', // 替换为临时 SMTP 密码、授权码或 API Key

        // 本机 DKIM 签名为可选配置，不需要时保持空值。
        'dkim_domain' => '',
        'dkim_private' => '',
        'dkim_selector' => 'default',
        'dkim_passphrase' => '',
    ],
];