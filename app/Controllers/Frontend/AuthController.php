<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\SafeHelper;

class AuthController extends \App\Controllers\Base\BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    // hook app_Controllers_Frontend_AuthController_start.php

    public function signUp(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_AuthController_signUp_start.php

        if (empty($this->appConfig['auth_sign_up_on'])) return $this->errorMessage($this->language->get('sign_up_not_on'), 9);

        // hook app_Controllers_Frontend_AuthController_signUp_before.php

        if (!empty($user['id'])) return $this->errorMessage($this->language->get('already_sign_in'), 2, '/', 2);

        $userToken = $this->getUserToken();

        // 获取导航栏信息
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_AuthController_signUp_center.php

        $page_link_string = 'auth/signUp'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('sign_up'),
                'keywords' => $this->language->get('sign_up'),
                'description' => $this->language->get('sign_up'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'user_token' => $userToken,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('auth/postSignUp'),
            'verify_email_on' => $this->appConfig['verify_email_on'],
            'signIn_by_code' => $this->appConfig['signIn_by_code'],
            'signIn_by_username' => $this->appConfig['signIn_by_username'],
            'language' => [
                'sign_up_to' => $this->language->get('sign_up_to'),
                'sign_up_to_description' => $this->language->get('sign_up_to_description'),
                'username' => $this->language->get('please_input_username'),
                'email' => $this->language->get('please_input_email'),
                'password' => $this->language->get('please_input_password'),
                'repeat_password' => $this->language->get('please_input_repeat_password'),
                'create_account' => $this->language->get('create_account'),
                'already_have_an_account' => $this->language->get('already_have_an_account'),
            ],
            'user' => [
                'signin' => [
                    'url' => $this->urlGenerator->url('auth/signIn'),
                    'label' => $this->language->get('sign_in'),
                ],
            ],
        ];

        // hook app_Controllers_Frontend_AuthController_signUp_after.php

        if ($this->appConfig['verify_email_on']) {
            // 邮件验证
            $data['verify_code_link'] = $this->urlGenerator->url('auth/postVerifyCode', ['verifyKey' => 'verifyEmail']);
            $data['language']['verification_code'] = $this->language->get('enter_the_verification_code');
            $data['language']['send_verification_code'] = $this->language->get('send_verification_code');
            $data['language']['send_successfully'] = $this->language->get('send_success');
        }

        // hook app_Controllers_Frontend_AuthController_signUp_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'sign_up'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    // 注册用户post
    public function postSignUp(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Frontend_AuthController_postSignUp_start.php

        if (empty($this->appConfig['auth_sign_up_on'])) return $this->errorMessage($this->language->get('sign_up_not_on'), 9, '/');

        $user = $request->getAttribute('user', []);
        if (!empty($user['id'])) return $this->errorMessage($this->language->get('already_sign_in'), 2, '/');

        $tokenLifetime = 86400 * 365 * 3;

        // hook app_Controllers_Frontend_AuthController_postSignUp_before.php

        $username = '';
        $signInByUsername = (int)$this->appConfig['signIn_by_username'] ?? 0;
        // 用户名登录
        if ($signInByUsername) {
            $username = RequestUtils::param('username', '');
            if (!$username) return $this->errorMessage($this->language->get('username_is_empty'), 'username');

            $msg = $this->validateUsername($username);
            if ($msg !== 'success') return $this->errorMessage($msg, 'username');
        }

        $email = RequestUtils::param('email', '');
        if (!$email) return $this->errorMessage($this->language->get('email_is_empty'), 'email');

        $msg = $this->validateEmail($email);
        if ($msg !== 'success') return $this->errorMessage($msg, 'email');

        if (0 == $signInByUsername) $username = explode('@', $email)[0];

        $password = RequestUtils::param('password', '');
        if (!$password) return $this->errorMessage($this->language->get('password_is_empty'), 'password');

        if (strlen($password) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');

        $result = SafeHelper::isValidPassword($password);
        if (false === $result) return $this->errorMessage($this->language->get('password_format'), 'password');

        $repeatPassword = RequestUtils::param('repeatPassword', '');
        if (!$repeatPassword) return $this->errorMessage($this->language->get('password_is_empty'), 'repeatPassword');

        if ($password !== $repeatPassword) return $this->errorMessage($this->language->get('repeat_password_incorrect'), 'repeatPassword');

        // hook app_Controllers_Frontend_AuthController_postSignUp_center.php

        $result = $this->userService->readByUsername($username);
        if ($signInByUsername) {
            if (!empty($result)) return $this->errorMessage($this->language->get('username_is_in_use'), 'username');
        } else {
            if ($result) $username = $username . date('ymdHis') . random_int(1000, 9999);
            $result = $this->userService->readByUsername($username);
            if (!empty($result)) {
                $username = date('ymdHis') . SafeHelper::randomStr(8) . random_int(1000, 9999);
            }
        }

        $result = $this->userService->readByEmail($email);
        if (!empty($result)) return $this->errorMessage($this->language->get('email_is_in_use'), 'email');

        // 邮件验证
        if ($this->appConfig['verify_email_on']) {
            $postCode = RequestUtils::param('code', '');
            if (!$postCode) return $this->errorMessage($this->language->get('verification_code_is_empty'), 'code');

            $verificationService = $this->emailVerificationService();
            if (!$verificationService->verify($this->userSession, 'verifyEmail', $email, $postCode)) {
                return $this->errorMessage($this->language->get('comparison_data_error'), 8);
            }
        }

        // hook app_Controllers_Frontend_AuthController_postSignUp_middle.php

        $time = time();
        $userData = [
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'salt' => SafeHelper::randomStr(16),
            'group_id' => 101,
            'create_ip' => $this->ip,
            'created_at' => $time,
            'logins' => 1,
            'login_date' => $time,
            'login_ip' => $this->ip,
        ];

        // hook app_Controllers_Frontend_AuthController_postSignUp_insert.php

        $userId = $this->userService->insert($userData);
        if (empty($userId)) return $this->errorMessage($this->language->get('failed_to_create_user'), -1);

        $this->emailVerificationService()->consume($this->userSession, 'verifyEmail');

        // hook app_Controllers_Frontend_AuthController_postSignUp_after.php
        $userId = (int)$userId;
        $this->userSession->set('user_id', $userId);
        $this->userService->tokenSet($userId, $tokenLifetime);

        // hook app_Controllers_Frontend_AuthController_postSignUp_end.php

        return $this->successMessage($this->language->get('create_success'), 0, '/');
    }

    // 用户登录页
    public function signIn(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];
        $codeSignInEnabled = $this->isCodeSignInEnabled();

        // hook app_Controllers_Frontend_AuthController_signIn_start.php

        if (!empty($user['id'])) return $this->errorMessage($this->language->get('already_sign_in'), 2, '/', 2);

        $this->userSession->delete('verifyEmail');

        // hook app_Controllers_Frontend_AuthController_signIn_before.php

        $userToken = $this->getUserToken();

        // 获取导航栏信息
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_AuthController_signIn_after.php

        $page_link_string = 'auth/signIn'; // 当前页链接字符串
        $signIn_input = $this->language->get('email');
        if ($this->appConfig['signIn_by_username']) $signIn_input .= ' / ' . $this->language->get('username');

        $data = [
            'header' => [
                'title' => $this->language->get('sign_in'),
                'keywords' => $this->language->get('sign_in'),
                'description' => $this->language->get('sign_in'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'user_token' => $userToken,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('auth/postSignIn'),
            'verify_email_on' => $this->appConfig['verify_email_on'],
            'signIn_by_code' => $codeSignInEnabled,
            'signIn_by_username' => $this->appConfig['signIn_by_username'],
            'user_resetpw_on' => $this->isPasswordResetEnabled(),
            'signIn_input' => $signIn_input,
            'language' => [
                'sign_in_to' => $this->language->get('sign_in_to'),
                'sign_in_to_description' => $this->language->get('sign_in_to_description'),
                'username' => $this->language->get('please_input_username'),
                'email' => $this->language->get('email'),
                'password' => $this->language->get('password'),
                'remember_me' => $this->language->get('remember_me'),
                'submit' => $this->language->get('submit'),
                'forget_password' => $this->language->get('forget_password'),
                'sign_up_an_account' => $this->language->get('sign_up_an_account'),
            ],
            'user' => [
                'signup' => [
                    'url' => $this->urlGenerator->url('auth/signUp'),
                    'label' => $this->language->get('sign_up'),
                ],
                'reset_password' => [
                    'url' => $this->urlGenerator->url('auth/resetPassword'),
                    'label' => $this->language->get('reset_password'),
                ],
            ],
        ];

        // 邮件验证
        if ($codeSignInEnabled) {
            $data['verify_code_link'] = $this->urlGenerator->url('auth/postVerifyCode', ['verifyKey' => 'codeSignIn']);
            $data['language']['verification_code'] = $this->language->get('verification_code');
            $data['language']['send_verification_code'] = $this->language->get('send_verification_code');
            $data['language']['send_successfully'] = $this->language->get('send_success');
        }

        // hook app_Controllers_Frontend_AuthController_signIn_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'sign_in'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    // 用户登录post
    public function postSignIn(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Frontend_AuthController_postSignIn_start.php

        $codeSignInEnabled = $this->isCodeSignInEnabled();
        if (empty($this->appConfig['signIn_by_username']) && !$codeSignInEnabled) return $this->errorMessage($this->language->get('sign_in_is_not_enabled'), 9, '/');

        $user = $request->getAttribute('user', []);
        if (!empty($user['id'])) return $this->errorMessage($this->language->get('already_sign_in'), 2, '/');

        $remember = RequestUtils::param('remember', 0);
        $tokenLifetime = $remember ? 86400 * 365 * 3 : 86400;

        // hook app_Controllers_Frontend_AuthController_postSignIn_before.php

        // 邮件验证码登录
        if ($codeSignInEnabled) {
            $post_code = RequestUtils::param('code', '');
            if (!$post_code) return $this->errorMessage($this->language->get('verification_code_is_empty'), 'code');

            $email = RequestUtils::param('email', '');
            if (!$email) return $this->errorMessage($this->language->get('email_is_empty'), 'email');

            $msg = $this->validateEmail($email);
            if ($msg !== 'success') return $this->errorMessage($msg, 'email');

            $verificationService = $this->emailVerificationService();
            if (!$verificationService->verify($this->userSession, 'codeSignIn', $email, $post_code)) {
                return $this->errorMessage($this->language->get('verification_code_error'), 7);
            }

            $user = $this->userService->readByEmail($email);
            if (empty($user)) return $this->errorMessage($this->language->get('email_not_exists'), 'email');

        }

        // hook app_Controllers_Frontend_AuthController_postSignIn_center.php

        // 密码登录
        if (!$codeSignInEnabled) {
            $username = RequestUtils::param('username', '');
            if (!$username) return $this->errorMessage($this->language->get('username_is_empty'), 'username');

            // hook app_Controllers_Frontend_AuthController_postSignIn_username.php

            $arr = explode('@', $username);
            if (empty($arr[1])) {
                // 用户名登录
                if (!$this->appConfig['signIn_by_username']) return $this->errorMessage($this->language->get('please_input_email'), 'username');

                $msg = $this->validateUsername($username);
                if ($msg !== 'success') return $this->errorMessage($msg, 'username');

                // hook app_Controllers_Frontend_AuthController_postSignIn_username_before.php

                $user = $this->userService->readByUsername($username);
                if (empty($user)) return $this->errorMessage($this->language->get('username_not_exists'), 'username');

                // hook app_Controllers_Frontend_AuthController_postSignIn_username_after.php
            } else {
                // 邮箱登录
                if (!$username) return $this->errorMessage($this->language->get('email_is_empty'), 'username');

                $msg = $this->validateEmail($username);
                if ($msg !== 'success') return $this->errorMessage($msg, 'username');

                // hook app_Controllers_Frontend_AuthController_postSignIn_email_before.php

                $user = $this->userService->readByEmail($username);
                if (empty($user)) return $this->errorMessage($this->language->get('email_not_exists'), 'username');

                // hook app_Controllers_Frontend_AuthController_postSignIn_email_after.php
            }

            $password = RequestUtils::param('password', '');
            if (!$password) return $this->errorMessage($this->language->get('password_is_empty'), 'password');

            if (strlen($password) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');

            // hook app_Controllers_Frontend_AuthController_postSignIn_username_center.php

            if (false === password_verify($password, $user['password'])) return $this->errorMessage($this->language->get('incorrect_password'), 'password');
        }

        $this->userSession->delete('user_token');
        $this->userSession->delete('verify_email');
        $update = ['login_ip' => $this->ip, 'login_date' => time(), 'logins+' => 1];

        // hook app_Controllers_Frontend_AuthController_postSignIn_after.php

        $result = $this->userService->update((int)$user['id'], $update);
        if (!$result) return $this->errorMessage($this->language->get('update_failed'), -1);

        // 防止 Session fixation
        $this->userSession->regenerate();
        $token = $this->userService->tokenSet((int)$user['id'], $tokenLifetime);
        if (!$token) return $this->errorMessage($this->language->get('update_failed'), -1);

        $user = $this->userService->safe($user);
        $this->userSession->set('user_id', $user['id']);
        if ($codeSignInEnabled) {
            $this->emailVerificationService()->consume($this->userSession, 'codeSignIn');
        }

        // hook app_Controllers_Frontend_AuthController_postSignIn_end.php

        return $this->successMessage($this->language->get('sign_in_success'), 0, '/');
    }

    // 发送邮箱验证码或密码
    public function postVerifyCode(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);

        // hook app_Controllers_Frontend_AuthController_postVerifyCode_start.php

        if (empty($this->appConfig['verify_email_on'])) return $this->errorMessage($this->language->get('not_enabled'), 9, '/');

        $key = RequestUtils::param('key', '');
        if (!$key) return $this->errorMessage($this->language->get('token_key_is_empty'), 6);

        $verificationService = $this->emailVerificationService();
        if (!$verificationService->isAllowedPurpose($key)) {
            return $this->errorMessage($this->language->get('illegal_operation'), 15);
        }

        if ('verifyEmail' === $key && empty($this->appConfig['auth_sign_up_on'])) {
            return $this->errorMessage($this->language->get('not_enabled'), 9, '/');
        }
        if ('codeSignIn' === $key && !$this->isCodeSignInEnabled()) {
            return $this->errorMessage($this->language->get('not_enabled'), 9, '/');
        }
        if ('resetPassword' === $key && !$this->isPasswordResetEnabled()) {
            return $this->errorMessage($this->language->get('not_enabled'), 9, '/');
        }

        $email = RequestUtils::param('email', '');
        if (!$email) return $this->errorMessage($this->language->get('email_is_empty'), 'email');

        $msg = $this->validateEmail($email);
        if ($msg !== 'success') return $this->errorMessage($msg, 'email');

        // 注册账号，发送验证码需要检查邮箱是否被使用
        if ('verifyEmail' === $key) {
            $result = $this->userService->readByEmail($email);
            if (!empty($result)) return $this->errorMessage($this->language->get('email_is_in_use'), 'email');
        }

        // 验证原始邮箱
        if ('originalEmail' === $key) {
            // 必须登录状态
            if (empty($user['id'])) return $this->errorMessage($this->language->get('please_sign_in'), 8, $this->urlGenerator->url('auth/signIn'));

            $user = $this->userService->read($user['id']);
            if (empty($user['email'])) return $this->errorMessage($this->language->get('please_sign_in'), 8, $this->urlGenerator->url('auth/signIn'));

            if ($email !== $user['email']) return $this->errorMessage($this->language->get('email_is_error'), 'email');
        }

        // 添加新邮箱
        if ('modifyEmail' === $key) {
            // 必须登录状态
            if (empty($user['id'])) return $this->errorMessage($this->language->get('please_sign_in'), 8, $this->urlGenerator->url('auth/signIn'));

            $result = $this->userService->readByEmail($email);
            if (!empty($result)) return $this->errorMessage($this->language->get('email_is_in_use'), 'email');
        }

        // 找回密码或验证码登录，发送验证码需要检查邮箱是否存在
        if (in_array($key, ['resetPassword', 'codeSignIn'])) {
            $result = $this->userService->readByEmail($email);
            if (empty($result)) return $this->errorMessage($this->language->get('email_not_exists'), 'email');
        }

        // hook app_Controllers_Frontend_AuthController_postVerifyCode_before.php
        $remainingCooldown = $verificationService->remainingCooldown($this->userSession, $key);
        if ($remainingCooldown > 0) {
            return $this->errorMessage(
                $this->language->get('please_wait_a_moment', ['n' => $remainingCooldown]),
                429
            );
        }

        $code = (string)SafeHelper::randomSixDigit();

        // hook app_Controllers_Frontend_AuthController_postVerifyCode_after.php

        // 执行邮件发送 (封装逻辑)
        $subject = $this->language->get('email_verification_code', ['name' => $this->appConfig['sitename']]);
        $body = $this->language->get('verification_code_body', ['website' => $this->appConfig['sitename'], 'code' => $code]);

        // hook app_Controllers_Frontend_AuthController_postVerifyCode_end.php

        $mailService = $this->container->get(\App\Services\System\MailService::class);
        $result = $mailService->send($email, $subject, $body);

        if (($result['status'] ?? '') !== 'success') {
            $mailMessage = (string)($result['message'] ?? 'configuration_error');
            $publicMessages = ['mailbox_not_configured', 'configuration_error', 'mail_connection_failed', 'mail_authentication_failed', 'mail_send_failed'];
            $publicMessage = in_array($mailMessage, $publicMessages, true) ? $mailMessage : 'mail_send_failed';
            return $this->errorMessage($this->language->get($publicMessage), 12);
        }

        $verificationService->store($this->userSession, $key, $email, $code);

        return $this->successMessage($this->language->get('send_success'), 0);
    }

    // 退出登录
    public function logout(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Frontend_AuthController_logout_start.php
        $this->userService->tokenClear();
        // hook app_Controllers_Frontend_AuthController_logout_end.php
        return $this->successMessage($this->language->get('already_logout'), 0, '/', 1);
    }

    // 重置密码
    public function resetPassword(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isPasswordResetEnabled()) return $this->errorMessage($this->language->get('not_enabled'), 9, '/');

        $user = $request->getAttribute('user', []);
        if (!empty($user['id'])) return $this->errorMessage($this->language->get('already_sign_in'), 2, '/', 2);

        $userId = $this->emailVerificationService()->resetAuthorizedUserId($this->userSession);
        $step = $userId ? 2 : 1;

        $userToken = $this->getUserToken();
        $navigation = $this->getNavigation();

        $page_link_string = 'auth/resetPassword';
        $data = [
            'header' => [
                'title' => $this->language->get('reset_password'),
                'keywords' => $this->language->get('reset_password'),
                'description' => $this->language->get('reset_password'),
            ],
            'navigation' => $navigation,
            'user_token' => $userToken,
            'page_link_string' => $page_link_string,
            'step' => $step,
            'action' => $this->urlGenerator->url($step === 1 ? 'auth/postResetVerify' : 'auth/postResetPassword'),
            'verify_code_link' => $this->urlGenerator->url('auth/postVerifyCode', ['verifyKey' => 'resetPassword']),
            'language' => [
                'reset_password' => $this->language->get('reset_password'),
                'reset_password_description' => $this->language->get('reset_password_description'),
                'email' => $this->language->get('please_input_email'),
                'verification_code' => $this->language->get('verification_code'),
                'send_verification_code' => $this->language->get('send_verification_code'),
                'new_password' => $this->language->get('new_password'),
                'repeat_password' => $this->language->get('repeat_password'),
                'submit' => $this->language->get('submit'),
                'next_step' => $this->language->get('next_step'),
                'send_success' => $this->language->get('send_success'),
                'back_to_sign_in' => $this->language->get('back_to_sign_in'),
                'step_1' => $this->language->get('verify_identity'),
                'step_2' => $this->language->get('set_new_password'),
            ],
            'user' => [
                'signin' => [
                    'url' => $this->urlGenerator->url('auth/signIn'),
                    'label' => $this->language->get('sign_in'),
                ],
            ],
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'reset_password'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    // 重置密码 - 步骤1：身份验证
    public function postResetVerify(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isPasswordResetEnabled()) return $this->errorMessage($this->language->get('not_enabled'), 9, '/');

        $email = RequestUtils::param('email', '');
        $code = RequestUtils::param('code', '');

        if (!$email) return $this->errorMessage($this->language->get('email_is_empty'), 'email');
        if (!$code) return $this->errorMessage($this->language->get('verification_code_is_empty'), 'code');

        $user = $this->userService->readByEmail($email);
        if (empty($user)) return $this->errorMessage($this->language->get('email_not_exists'), 'email');

        // 禁止封禁用户找回密码
        if (6 == $user['group_id']) return $this->errorMessage($this->language->get('user_is_banned'), 6);

        // 验证验证码
        $verificationService = $this->emailVerificationService();
        if (!$verificationService->verify($this->userSession, 'resetPassword', $email, $code)) {
            return $this->errorMessage($this->language->get('comparison_data_error'), 8);
        }

        // 身份核验通过，记录带时效的 User ID 到 Session，并一次性消费验证码
        $verificationService->storeResetAuthorization($this->userSession, (int)$user['id']);
        $verificationService->consume($this->userSession, 'resetPassword');

        return $this->successMessage($this->language->get('operation_success'), 0, $this->urlGenerator->url('auth/resetPassword'));
    }

    // 重置密码 - 步骤2：设置新密码
    public function postResetPassword(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isPasswordResetEnabled()) return $this->errorMessage($this->language->get('not_enabled'), 9, '/');

        $verificationService = $this->emailVerificationService();
        $userId = $verificationService->resetAuthorizedUserId($this->userSession);
        if (!$userId) return $this->errorMessage($this->language->get('illegal_operation'), 15, $this->urlGenerator->url('auth/resetPassword'));

        $password = RequestUtils::param('password', '');
        $repeatPassword = RequestUtils::param('repeatPassword', '');

        if (!$password) return $this->errorMessage($this->language->get('password_is_empty'), 'password');
        if (mb_strlen($password) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');
        if (!SafeHelper::isValidPassword($password)) return $this->errorMessage($this->language->get('password_format'), 'password');
        if ($password !== $repeatPassword) return $this->errorMessage($this->language->get('repeat_password_incorrect'), 'repeatPassword');

        $user = $this->userService->read($userId);
        if (empty($user)) {
            $verificationService->clearResetAuthorization($this->userSession);
            return $this->errorMessage($this->language->get('user_not_exists'), -1, $this->urlGenerator->url('auth/resetPassword'));
        }

        // 更新密码并增加登录版本（全端退出现有 Token）
        $updateData = [
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'login_version+' => 1,
            'salt' => SafeHelper::randomStr(16),
        ];

        if ($this->userService->update($userId, $updateData)) {
            $verificationService->clearResetAuthorization($this->userSession);
            return $this->successMessage($this->language->get('save_success'), 0, $this->urlGenerator->url('auth/signIn'));
        }

        return $this->errorMessage($this->language->get('update_failed'), -1);
    }

    private function emailVerificationService(): \App\Services\Auth\EmailVerificationService
    {
        return $this->container->get(\App\Services\Auth\EmailVerificationService::class);
    }

    private function isCodeSignInEnabled(): bool
    {
        return !empty($this->appConfig['signIn_by_code'])
            && !empty($this->appConfig['verify_email_on']);
    }

    private function isPasswordResetEnabled(): bool
    {
        return !empty($this->appConfig['user_resetpw_on'])
            && !empty($this->appConfig['verify_email_on']);
    }

    // 生成Token
    private function getUserToken(): string
    {
        $this->tokenService->captureContext($this->request);
        return $this->tokenService->generateToken();
    }

    // hook app_Controllers_Frontend_AuthController_end.php
}
