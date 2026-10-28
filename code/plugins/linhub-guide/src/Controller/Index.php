<?php

declare(strict_types=1);

namespace Lg\Pack\LinhubGuide\Controller;

use linhub\admin\controller\AdminController;
use think\Request;

/**
 * 功能包介绍 - 首页控制器
 */
class Index extends AdminController
{
    /**
     * 功能包介绍页面
     * @param Request $request
     * @return string
     */
    public function index(Request $request): string
    {
        return $this->fetch('index');
    }
}
