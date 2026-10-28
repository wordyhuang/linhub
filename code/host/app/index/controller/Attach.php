<?php

declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use linhub\host\services\attachment\AttachmentDenied;
use linhub\host\services\attachment\AttachmentNotFound;
use linhub\host\services\attachment\AttachmentService;
use linhub\host\support\HostBootstrap;
use think\Response;

/**
 * 附件公开下载入口（AT-4/AT-8）。
 *
 * 统一 URL：/index/attachment/download?token=xxx（Local/Qiniu 均指向此入口）。
 *  - 公开附件：免鉴权直接返回字节；
 *  - 私有附件：由 AttachmentService 按当前登录身份裁决（超管或上传者）；
 *  - 图片缺失/删除/过期：返回默认图片；非图片返回 404。
 *
 * 本应用（index）不挂登录中间件，未登录访问私有附件由服务层抛 Denied 后返回 403。
 * 控制器类名避开应用名 index/分组名 attachment，防止多应用路由把路径误判为控制器。
 */
class Attach extends BaseController
{
    /**
     * 下载/预览附件。
     *
     * 可选参数 image=1：调用方声明该 token 用于图片场景，当 token 完全无元数据时
     * 也回退默认图片（用于外链图片位等无法预知附件是否存在的场景）。
     */
    public function download(): Response
    {
        $token = trim((string) $this->request->param('token', ''));
        $asImage = (int) $this->request->param('image', 0) === 1;
        if ($token === '') {
            return $this->notFound();
        }

        $service = new AttachmentService();
        $viewer = (new HostBootstrap($this->app))->currentUser();

        try {
            $result = $service->fetch($token, $viewer);
        } catch (AttachmentDenied $e) {
            return $this->forbidden($e->getMessage());
        } catch (AttachmentNotFound $e) {
            if ($asImage) {
                return $this->buildResponse(
                    (string) ($fb = $service->fallbackImage())['content'],
                    (string) $fb['mime'],
                    'fallback' . ($fb['ext'] ?? '.svg')
                );
            }
            return $this->notFound();
        }

        return $this->buildResponse(
            (string) $result['content'],
            (string) $result['mime'],
            (string) $result['name']
        );
    }

    private function buildResponse(string $content, string $mime, string $name): Response
    {
        return response($content)
            ->contentType($mime !== '' ? $mime : 'application/octet-stream')
            ->header([
                'Content-Length'    => (string) strlen($content),
                'Content-Disposition' => 'inline; filename="' . rawurlencode($name) . '"',
                'Cache-Control'     => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    private function notFound(): Response
    {
        return response('Not Found', 404)->contentType('text/plain; charset=utf-8');
    }

    private function forbidden(string $message): Response
    {
        return response($message !== '' ? $message : 'Forbidden', 403)
            ->contentType('text/plain; charset=utf-8');
    }
}
