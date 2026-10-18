<?php

namespace App\Models;

class FileStorageModel extends BaseModel
{
    /** @var string */
    protected $table = 'file_storage';

    /** 上传链路保护：写入、恢复及清理前核对所属主库事实，页面读取仍走原合同。 */
    public function readOnMaster(int $id): array
    {
        return $this->readFromMaster(['id' => $id]);
    }
}
