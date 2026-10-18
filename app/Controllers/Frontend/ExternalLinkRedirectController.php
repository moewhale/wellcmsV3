<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\Base\BaseController;
use App\Services\LinkService;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;

class ExternalLinkRedirectController extends BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    /**
     * 外链安全跳转提示页
     */
    public function external(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $target = RequestUtils::param('target', '');
        $url = base64_decode(rawurldecode($target), true);

        // 公开入口必须自行完成最终协议校验，不能依赖只在 UGC 渲染链运行的前置过滤。
        if (empty($url) || !$this->isAllowedTargetUrl($url)) {
            return $this->errorMessage($this->language->get('error_param'), 400);
        }

        // 如果目标实际上是站内或白名单（配置变更导致），直接 302 跳转。
        $linkService = $this->container->get(LinkService::class);
        if ($linkService->isInternal($url)) {
            $responseFactory = $this->container->get(\Framework\Http\Interfaces\ResponseFactoryInterface::class);
            return $responseFactory->createResponse(302)->withHeader('Location', $url);
        }

        $data = [
            'header' => [
                'title' => $this->language->get('external_link_title'),
            ],
            'target_url' => $url,
            'navigation' => $this->getNavigation(),
            'language' => [
                'external_link_title' => $this->language->get('external_link_title'),
                'external_link_warning' => $this->language->get('external_link_warning'),
                'external_link_continue' => $this->language->get('external_link_continue'),
                'cancel' => $this->language->get('cancel'),
            ],
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'external_link_redirect'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    /**
     * 外链跳转页的最终目标白名单。
     *
     * filter_var() 只验证 URL 结构，会接受 javascript/file/data 等协议，
     * 因此这里必须在直跳和模板输出前独立限定可点击协议。
     */
    private function isAllowedTargetUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 4096) {
            return false;
        }
        if (preg_match('/[\\x00-\\x20\\x7f\\\\]/', $url)) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true);
    }
}
