<?php

namespace App\Models;

class CacheModel extends BaseModel
{
    /** @var string */
    protected $table = 'cache';

    public function flushAll()
    {
        $this->db->truncate('cache');
        return true;
    }
}