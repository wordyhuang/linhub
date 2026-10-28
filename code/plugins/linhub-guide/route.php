<?php
// 功能包路由声明
use think\facade\Route;
use Lg\Pack\LinhubGuide\Controller\Index;

Route::get('linhub-guide', Index::class . '@index');
Route::get('linhub-guide/index', Index::class . '@index');