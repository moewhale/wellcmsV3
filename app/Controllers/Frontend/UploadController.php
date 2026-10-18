<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use App\Controllers\Base\BaseController;

// 所有上传和存搞都应走上传临时目录，提交后再移动到最终目录，避免未完成的上传文件被误用。
/*
 * 上传链路保护（用户要求，2026-10-10；见根目录 AGENTS.md）：
 * 此入口由论坛、文章等插件共用。未经用户明确授权，AI 禁止修改上传行为；
 * 审计、重构、优化或修复测试均不构成授权，必须保留此备注。
 */

class UploadController extends BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    /** @var object */
    private $uploadService;
    /** @var object */
    private $attachmentService;

    public function __construct(
        \Framework\Http\Interfaces\ServerRequestInterface $request,
        \App\Controllers\Base\ResponseFormatter $responseFormatter,
        \App\Interfaces\LanguageLoaderInterface $language,
        \Framework\Http\Routing\UrlGeneratorInterface $urlGenerator,
        \App\Services\Auth\UserService $userService,
        \App\Services\System\MenuService $menuService,
        \App\Controllers\Base\TemplateManager $templateManager,
        \App\Services\Auth\TokenService $tokenService,
        array $appConfig,
        array $i18nConfig,
        \App\Services\Storage\AttachmentService $attachmentService,
        \App\Services\Storage\UploadService $uploadService,
        \Framework\Core\Container $container
    ) {
        parent::__construct(
            $request,
            $responseFormatter,
            $language,
            $urlGenerator,
            $userService,
            $menuService,
            $templateManager,
            $tokenService,
            $appConfig,
            $i18nConfig,
            $container
        );

        $this->uploadService = $uploadService;
        $this->attachmentService = $attachmentService;

        // 捕获请求上下文 (Session, Language, IP 等)
        $this->uploadService->captureContext($request);
    }

    // hook app_Controllers_Frontend_UploadController_start.php

    public function handle(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        try {
            $action = RequestUtils::param('action', '');
            switch ($action) {
                case 'init':
                    return $this->init($request);
                case 'upload_chunk':
                    return $this->uploadChunk($request);
                case 'complete':
                    return $this->complete($request);
                case 'direct':
                    return $this->direct($request);
                case 'delete_temp':
                    return $this->deleteTemp($request);
                default:
                    //不带 action 时，基于 chunks 判断
                    $isChunked = RequestUtils::param('chunks', 0) && RequestUtils::param('chunks', 0) > 1;
                    if ($isChunked) {
                        return $this->uploadChunk($request);
                    }
                    return $this->direct($request);
            }
        } catch (\App\Exception\UploadClientException $e) {
            // 仅转换已审查、可由上传客户端纠正的业务错误。
            return $this->errorMessage($e->getMessage(), (int)$e->getCode() ?: 1);
        }
    }

    /**
     * 初始化
     * POST /upload/?action=init
     */
    public function init(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->container->get(\App\Services\Storage\TempCleanupService::class)->maybeTriggerGC();
        } catch (\Throwable $e) {
            // 降级：不阻断上传流程
        }
        $user = $request->getAttribute('user') ?? [];

        $result = $this->uploadService->init(
            $user,
            RequestUtils::param('filename', ''),
            RequestUtils::param('filesize', 0),
            RequestUtils::param('mime', ''),
            RequestUtils::param('filehash', ''),
            RequestUtils::param('preferred_chunk_size', 0),
            (int)RequestUtils::param('is_attachment', 0)
        );

        if ($result['is_fast'] ?? false) {
            return $this->jsonResponse(array_merge(['code' => 0], $result));
        }

        // 直接返回 Service 生成的 data 块，并补充根级 code:0
        return $this->jsonResponse(['code' => 0, 'data' => $result['data']]);
    }

    /**
     * 上传分片
     * POST /upload/?action=chunk
     */
    public function uploadChunk(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $uploadId = RequestUtils::param('uploadId', '');
        $chunkIdx = RequestUtils::param('chunk', 0);
        $chunks   = RequestUtils::param('chunks', 0);

        $file = $this->getValidUploadedFile($request);
        if (!$file) return $this->errorMessage('Missing file', 1);

        $result = $this->uploadService->saveChunk($user, $uploadId, $chunkIdx, $chunks, $file);

        return $this->jsonResponse(array_merge(['code' => 0], $result));
    }

    /**
     * 合并分片
     * POST /upload/?action=complete
     */
    public function complete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $uploadId = RequestUtils::param('uploadId', '');
        $filehash = RequestUtils::param('filehash', '');
        $tempId   = (string)(RequestUtils::param('tempId', '') ?: ($this->userSession ? $this->userSession->get('temp_id', '') : ''));

        $isAttachment = (int)RequestUtils::param('is_attachment', 0);
        $result = $this->uploadService->complete($user, $uploadId, $filehash, $tempId, $isAttachment);

        if ($result['is_fast'] ?? false) {
            return $this->jsonResponse(array_merge(['code' => 0], $result));
        }

        // 补充根级 code:0
        return $this->jsonResponse(array_merge(['code' => 0], $result));
    }

    /**
     * 小文件直传
     * POST /upload/?action=direct
     */
    public function direct(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $filehash = RequestUtils::param('filehash', '');
        $tempId   = (string)(RequestUtils::param('tempId', '') ?: ($this->userSession ? $this->userSession->get('temp_id', '') : ''));

        $isAttachment = (int)RequestUtils::param('is_attachment', 0);
        $file = $this->getValidUploadedFile($request);
        if (!$file) return $this->errorMessage('Missing file', 1);

        $filename = (string)(RequestUtils::param('filename', '') ?: ($file->getClientFilename() ?: 'file'));

        $filesize = (int)(RequestUtils::param('filesize', 0) ?: ($file->getSize() ?: 0));

        $result = $this->uploadService->direct($user, $file, $filename, $filesize, $filehash, $tempId, $isAttachment);

        if ($result['is_fast'] ?? false) {
            return $this->jsonResponse(array_merge(['code' => 0], $result));
        }

        // 补充根级 code:0
        return $this->jsonResponse(array_merge(['code' => 0], $result));
    }

    /**
     * 获取有效的上传文件对象
     */
    private function getValidUploadedFile(\Framework\Http\Interfaces\ServerRequestInterface $request): ?\Framework\Http\Interfaces\UploadedFileInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        foreach ($uploadedFiles as $f) {
            if ($f instanceof \Framework\Http\Interfaces\UploadedFileInterface && $f->getError() === UPLOAD_ERR_OK) {
                return $f;
            }
        }
        return null;
    }

    private function jsonResponse(array $data): ResponseInterface
    {
        // 绕过 BaseController::message，直接调用 Formatter 生成纯净 JSON。
        // 防止框架 initializeCommonData 往 data 键里注入垃圾数据。
        return $this->responseFormatter->jsonResponseFormat($data);
    }

    /**
     * 查看我的上传记录（list）
     * GET /my/upload/list?page=1&pagesize=20
     */
    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        $userId = (int)$user['id'];
        // 获取分页原始参数（路由段或 query 都可能）
        $page = RequestUtils::param('page', 1);
        $cursorId = RequestUtils::param('cursorId', null);
        $dirFlag = RequestUtils::param('dirFlag', 'next');
        $masterId = RequestUtils::param('masterId', 0);
        $maxId = RequestUtils::param('maxId', 0);

        $pageSize = max(10, min(100, RequestUtils::param('pageSize', 20)));
        $hasMore = false;
        $dataList = [];

        0 === $maxId && $maxId = (int)$this->attachmentService->maxid();

        // 适配器：锁定在 <= nodeId 的子集内翻页（baseOnFirstOnly=false）
        $adapter = BaseController::makeGenericAdapter([$this->attachmentService, 'findByUserIdPaged'], [
            'orderKey' => 'id',
            'indexKey' => 'id',
            'baseCondition' => ['<=' => $maxId], // 子集上界
            'conditionBuilder' => [BaseController::class, 'simpleConditionBuilder'],
            'baseOnFirstOnly' => false, // 锁定子集 <=$maxId
            'hasMasterId' => true,
            'masterId' => $userId,
        ]);

        // hook app_Controllers_Frontend_UploadController_list_before.php

        // cursor 分页
        [$dataList, $hasMore, $firstId, $lastId] = $this->fetchPaged($adapter, $pageSize, $cursorId, 'id', -1, $dirFlag, false);

        // 格式化文件大小
        if (!empty($dataList)) foreach ($dataList as &$item) {
            $item['filesize_formatted'] = \Framework\Utils\FormatHelper::humanSize((int)($item['filesize'] ?? 0));
        }
        unset($item);

        // hook app_Controllers_Frontend_UploadController_list_center.php

        $navigation = $this->getNavigation();

        $page_link_string = 'my/upload'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('my_home'),
                'keywords' => $this->language->get('my_home'),
                'description' => $this->language->get('my_home'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'my', 'child' => 'home'],
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'pagination' => [
                'previous_link' => '',
                'next_link' => ''
            ],
            'item_list' => $dataList,
            'language' => [
                'module' => $this->language->get('module'),
                'image' => $this->language->get('image'),
                'file_name' => $this->language->get('file_name'),
                'file_size' => $this->language->get('file_size'),
                'creation_date' => $this->language->get('creation_date'),
                'comment' => $this->language->get('comment'),
                'review_note' => $this->language->get('review_note'),
                'previous' => $this->language->get('previous'),
                'next' => $this->language->get('next'),
            ]
        ];

        // hook app_Controllers_Frontend_UploadController_list_after.php

        // 构造分页链接
        if ($page > 1 && $firstId > 0) {
            $data['pagination']['previous_link'] = $this->urlGenerator->url($page_link_string, $extra + ['page' => ($page - 1), 'cursorId' => $firstId, 'dirFlag' => 'previous', 'masterId' => $masterId, 'maxId' => $maxId]);
        }

        if ($hasMore && $lastId > 0) {
            $data['pagination']['next_link'] = $this->urlGenerator->url($page_link_string, $extra + ['page' => ($page + 1), 'cursorId' => $lastId, 'dirFlag' => 'next', 'masterId' => $masterId, 'maxId' => $maxId]);
        }

        // hook app_Controllers_Frontend_UploadController_list_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'upload_list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 仅限管理员 删除单个文件（delete）
     * POST /upload/delete?id=123
     */
    public function delete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $userId = (int)($user['id'] ?? 0);
        $id = RequestUtils::param('id', 0);
        if (!$id) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'ID']), 7);

        // hook app_Controllers_Frontend_UploadController_delete_start.php

        $res = $this->attachmentService->deleteWithFiles($id, $userId);
        if (!$res) return $this->errorMessage($this->language->get('delete_failed'), -1);

        // hook app_Controllers_Frontend_UploadController_delete_end.php

        return $this->successMessage($this->language->get('delete_success'), 0);
    }

    /**
     * 仅限管理员 批量删除文件（batchDelete）
     * POST /upload/batchDelete?&ids=1,2,3
     */
    public function batchDelete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $userId = (int)($user['id'] ?? 0);
        $idsArray = RequestUtils::param('ids', []);
        if (empty($idsArray) || !is_array($idsArray)) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'ids']), 7);

        // hook app_Controllers_Frontend_UploadController_batchDelete_start.php

        if (count($idsArray) > 100) return $this->errorMessage($this->language->get('delete_limit', ['n' => 100]), 7);

        $res = $this->attachmentService->deleteWithFiles($idsArray, $userId);
        if (!$res) return $this->errorMessage($this->language->get('delete_failed'), -1);

        // hook app_Controllers_Frontend_UploadController_batchDelete_end.php

        return $this->successMessage($this->language->get('delete_success'), 0);
    }

    /**
     * 审核文件（review）- 路由权限检查：仅管理员
     * POST /upload/review?id=123&status=1&note=ok
     */
    public function review(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $userId = (int)($user['id'] ?? 0);

        // hook app_Controllers_Frontend_UploadController_review_start.php

        $id = (int)RequestUtils::param('id', 0);
        $status = (int)RequestUtils::param('status', 0); // 1=通过 2=拒绝
        $note = trim((string)RequestUtils::param('note', ''));

        // hook app_Controllers_Frontend_UploadController_review_before.php

        if (!$id) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'ID']), 1);
        if (!in_array($status, [1, 2], true)) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'Status']), 1);

        // hook app_Controllers_Frontend_UploadController_review_after.php

        $res = $this->attachmentService->review($id, $status, $note, $userId);
        if (!$res) return $this->errorMessage($this->language->get('modify_failed'), -1);

        // hook app_Controllers_Frontend_UploadController_review_end.php

        $msg = $status == 1 ? $this->language->get('review_passed') : $this->language->get('rejected');
        return $this->successMessage($msg, 0);
    }

    /**
     * 删除临时文件 (发帖前撤销上传)
     * POST /upload?action=delete_temp&hash=xxx
     */
    public function deleteTemp(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $hash = RequestUtils::param('hash', '');
        if (empty($hash)) {
            return $this->errorMessage($this->language->get('parameter_error', ['error' => 'hash']), 7);
        }

        $res = $this->uploadService->deleteTempByHash($hash);
        if (!$res) {
            return $this->errorMessage($this->language->get('item_not_exists', ['item' => 'hash']), 7);
        }

        return $this->successMessage($this->language->get('delete_success'), 0);
    }

    // hook app_Controllers_Frontend_UploadController_end.php

    /**
     * 下载绑定当前 Session 的临时签名附件链接。
     * GET /download/{id}?downloadExpires={timestamp}&downloadSignature={signature}
     *
     * 路由 requiresDownloadSignature 经 DownloadSignatureResolver 注入 DownloadSignatureMiddleware，
     * 先校验完整查询参数、Session 绑定与签名有效期；
     * 本方法继续执行所属模块授权、文件审核及文件流响应，成功响应为 private, no-store。
     *
     * 主程序 URL 生成示例：$attachmentId 为已授权附件 ID，签名参数由 DownloadService::createQueries() 计算。
     * $downloadUrl = $this->urlGenerator->url('download/' . $attachmentId, ['downloadExpires' => $timestamp, 'downloadSignature' => $signature]);
     * 有业务查询参数时一并参与签名并传入 url()；路由格式及语言前缀由主程序 URL 生成器和插件处理。
     */
    public function download(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $id = RequestUtils::param('id', 0);
        if (!$id) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'ID']), 1);

        // 1. 获取附件信息
        $attachment = $this->attachmentService->read($id);
        if (empty($attachment)) {
            return $this->errorMessage($this->language->get('item_not_exists', ['item' => 'Attachment']), 404);
        }

        // 所属模块先授权；未知模块保持原读取合同。
        $readContext = [];
        // hook app_Controllers_Frontend_UploadController_download_context.php

        // 2. 获取存储信息
        $storageService = $this->container->get(\App\Services\Storage\FileStorageService::class);
        $storage = $readContext
            ? $storageService->readOnMaster((int)$attachment['storage_id'])
            : $storageService->readByCache((int)$attachment['storage_id']);

        if (empty($storage) || empty($storage['path'])) {
            return $this->errorMessage($this->language->get('file_not_exists'), 404);
        }

        // 3. 审核门禁（权威状态在 well_file_storage）：待审(0)/拒绝(2) 的文件
        //    仅文件所有者与管理员（group_id=1）可下载，其余视为不存在。
        //    配合 nginx /upload/ 对 html/js/json/svg 的 deny，待审文件无法绕过。
        if ((int)($storage['is_reviewed'] ?? 0) !== 1) {
            $user = $request->getAttribute('user', []);
            $isOwner = (int)($attachment['user_id'] ?? 0) === (int)($user['id'] ?? 0);
            $isAdmin = (int)($user['group_id'] ?? 0) === 1;
            if (!$isOwner && !$isAdmin) {
                return $this->errorMessage($this->language->get('item_not_exists', ['item' => 'Attachment']), 404);
            }
        }

        // 上传保护：所属模块先核对内容权限与精确修订，核心随后统一选择文件源。
        $cloud = (int)($readContext['cloudReadyAt'] ?? 0) > 0;
        if ($cloud && ((int)($storage['cloud_type'] ?? 0) !== 1
            || (string)($storage['disk_key'] ?? '') !== (string)($readContext['cloudDisk'] ?? ''))) {
            throw new \RuntimeException('Content cloud state does not match attachment storage');
        }
        $diskKey = $cloud ? (string)$readContext['cloudDisk'] : 'local';
        try {
            $source = $storageService->openStoredFile($storage, $diskKey);
        } catch (\Framework\Exception\Http\NotFoundException $missing) {
            return $this->errorMessage($this->language->get('file_not_exists'), 404);
        }
        $orgFilename = $attachment['orgfilename'] ?: ($attachment['filename'] ?: basename((string)$storage['path']));
        $encodedName = rawurlencode($orgFilename);
        $stream = \Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStreamFromResource($source['stream']);
        $responseFactory = $this->container->get(\Framework\Http\Interfaces\ResponseFactoryInterface::class);
        $response = $responseFactory->createResponse(200)
            ->withHeader('Content-Type', $source['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $encodedName . '"; filename*=UTF-8\'\'' . $encodedName)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
        if ($source['size'] !== null) $response = $response->withHeader('Content-Length', (string)$source['size']);

        return $response;
    }
}
