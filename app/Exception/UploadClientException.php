<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 可由上传客户端纠正并安全公开的业务异常。
 *
 * 文件系统、存储、数据库及未知运行错误不得转换为此类型。
 */
class UploadClientException extends \Framework\Exception\BusinessException
{
}
