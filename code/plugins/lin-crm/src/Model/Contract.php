<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Model;

class Contract extends LinCrmModel
{
    protected string $name = 'contract';
    protected string $codePrefix = 'HT'; // GR-1

    /** 状态机 STM-CON（⛔OI-006 冻结版：draft→auditing→effective→settled/terminated） */
    public const STATUS = ['draft', 'auditing', 'effective', 'settled', 'terminated'];
}
