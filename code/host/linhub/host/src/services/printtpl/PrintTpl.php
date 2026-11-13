<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

/**
 * 单据打印模板服务契约（平台第 17 个公共服务，PT）。
 *
 * 定位：平台托管的可打印单据模板。平台侧（超管）通过可视化编辑器新增/编制模板并按
 * 模板编号（code）统一管理；功能包按编号请求渲染并传入业务数据，由平台产出可打印
 * HTML（纸张设置落为 @page，明细表跨页表头重复、页眉页脚页码由打印页呈现）。
 *
 * 归属双轨（T-1）：
 *  - 平台公共模板（scope=platform）：所有功能包可渲染；
 *  - 包级私有模板（scope=package，owner_pkg=包 short）：仅归属包可渲染，
 *    他包渲染抛 PrintTplDenied；停用模板抛 PrintTplDenied（消息「模板已停用」）。
 *
 * 调用方语义（与 Translator/RecycleBin 一致）：$package 为功能包短名；
 * 数据行内伪造归属类字段不影响归属判定，归属以契约调用 $package 为准（4.3）。
 *
 * 业务数据边界（PT-16）：仅接受 JSON 可表达结构（标量/数组/嵌套对象）；
 * 含资源、闭包等非法结构抛 PrintTplQuota。
 */
interface PrintTpl
{
    /**
     * 按模板编号渲染：把业务数据填入模板，返回完整可打印 HTML 文档（含 @page 与分页脚本）。
     *
     * @param string|null $package 调用方功能包短名（null 视为平台侧，仅平台公共模板可用）
     * @param string      $code    模板编号（如 invoice.standard）
     * @param array       $data    业务数据（点路径供模板取值；数组供明细循环）
     *
     * @throws PrintTplDenied  编号不存在 / 已停用 / 他包私有模板（无权使用）
     * @throws PrintTplQuota   业务数据非法（不可 JSON 序列化）或超出大小限制
     */
    public function render(?string $package, string $code, array $data): string;

    /**
     * 平台统一打印入口 URL（方式二）：平台页内渲染 + 浏览器打印，功能包无需自嵌打印页。
     *
     * 大数据传递（T-7 落地）：业务数据在服务端生成一次性票据（5 分钟有效、单次使用），
     * URL 仅携带票据 token，规避 GET 传参长度限制。
     *
     * @param array $options 保留（暂无）
     *
     * @throws PrintTplDenied / PrintTplQuota 同 render
     */
    public function printUrl(?string $package, string $code, array $data, array $options = []): string;

    /**
     * 服务端生成 PDF（T-3 后续项）：渲染打印文档并用无头浏览器打印为 PDF 文件。
     *
     * 打印质量与浏览器打印一致（同一渲染引擎：@page 纸张、分页脚本、条码 SVG 全部生效）。
     * 产物为绝对路径，位于系统临时目录，约 10 分钟后自动清理；调用方拿到路径后自行
     * 下载、归档或发送。需要服务器可检测到 Chrome/Edge（可用环境变量 PRINTTPL_PDF_BROWSER 指定）。
     *
     * @return array {file:string 绝对路径, name:string 建议文件名, bytes:int 文件字节数}
     *
     * @throws PrintTplDenied 编号不存在 / 已停用 / 他包私有 / 未检测到浏览器 / 生成失败
     * @throws PrintTplQuota  业务数据非法（不可 JSON 序列化）或超出大小限制
     */
    public function pdf(?string $package, string $code, array $data, array $options = []): array;

    /**
     * 按编号取模板元信息（不含正文）；不存在返回 null。
     *
     * @return array|null {code,name,scope,owner_pkg,status,paper,mode,orphaned_at,updated_at}
     */
    public function get(?string $package, string $code): ?array;
}
