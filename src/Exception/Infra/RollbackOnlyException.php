<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Exception\Infra;

/**
 * 嵌套事务已明确执行回滚后，由最外层 commit 传播的失败。
 */
class RollbackOnlyException extends DatabaseException
{
}
