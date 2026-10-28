<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 导入日志
 */
class ImportLog extends BaseModel
{
    protected $table = 'lg_lin-trade_import_log';

    protected $type = [
        'total_rows'   => 'int',
        'success_rows' => 'int',
    ];
}