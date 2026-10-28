<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Model;

class Opportunity extends LinCrmModel
{
    protected string $name = 'opportunity';
    protected string $codePrefix = 'SJ'; // GR-1

    /** 阶段顺序（单向推进，回退留痕） */
    public const STAGES = ['initial', 'confirm', 'proposal', 'negotiate'];
    /** 阶段→主状态终态 */
    public const WIN_RATES_DEFAULT = [10, 30, 50, 70, 100]; // 含 won=100
}
