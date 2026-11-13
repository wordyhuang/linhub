# LinHub 服务接入指南 · printtpl 单据打印模板服务 V1.4

> **文档创建时间**：2026-11-03 12:00:00
> **最后修改时间**：2026-11-03 19:26:00
> **当前版本**：V_1.4
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 printtpl 单据打印模板服务的契约方法、双轨归属、渲染语义、配额、一次性打印票据与服务端 PDF 生成规则。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 printtpl 一个服务；平台超管经可视化编辑器编制单据打印模板（组件拖拽 + 占位符源码双模式），功能包按模板编号传入业务数据请求渲染，平台产出可打印 HTML（@page 纸张、明细表跨页表头重复、页眉页脚页码、分组小计与单元格合并），经一次性打印票据打开统一打印页；亦支持服务端经无头浏览器直接产出 PDF 文件（质量与浏览器打印一致）。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 核心语义（双轨归属 / 渲染 / 占位符与格式化 / 一次性票据 / 服务端 PDF / 配额）
5. 复杂排版能力（跨页 / 页眉页脚页码 / 小计合并 / 布局组件 / 二维码条码 / 图片附件引用）
6. 包间隔离与生命周期（孤儿冻结 / 接管 / 数据随包）
7. 使用要点与避坑

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `printtpl` |
| 服务类型 | 托管服务（hosted） |
| 数据随包 | 支持（`PrintTplDataDriver`：仅包级私有模板随包，平台公共模板不随包） |
| 契约接口 | `linhub\host\services\printtpl\PrintTpl` |
| 宿主实现 | `linhub\host\services\printtpl\PrintTplService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |
| 管理入口 | 平台管理后台 → 平台管理 → 打印模板（权限点 `host:printtpl:view` / `host:printtpl:manage`） |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['printtpl'], ...]

$pt = HostServiceApi::getService($this->app, 'printtpl', 'demo');

// 功能包发起打印：生成一次性票据 URL（5 分钟有效、单次使用），前端 window.open 打开
$url = $pt->printUrl('demo', 'invoice.standard', [
    'order'  => ['no' => 'SO-2026-001', 'customer' => ['name' => '某某公司']],
    'items'  => [['name' => '耗材A', 'qty' => 2, 'price' => 12.5], ...],
]);
// 返回形如 /admin/printtpl_admin/print?token=<32位hex>
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `render` | `render(?string $package, string $code, array $data): string` | 按编号渲染：业务数据填入模板，返回完整可打印 HTML（含 @page 与分页脚本）；编号不存在/停用/他包私有模板抛 `PrintTplDenied`；数据非法或超限抛 `PrintTplQuota` |
| `printUrl` | `printUrl(?string $package, string $code, array $data, array $options = []): string` | 平台统一打印入口 URL（方式二，T-7）：数据落为一次性票据（5 分钟/单次/1MB），URL 仅带 token，规避 GET 长度限制 |
| `pdf` | `pdf(?string $package, string $code, array $data, array $options = []): array` | 服务端生成 PDF（T-3 后续项落地）：与浏览器打印同一引擎，经无头 Edge/Chrome `--print-to-pdf` 产出 PDF 文件；返回 `{file 绝对路径, name 建议文件名, bytes 字节数}`；浏览器缺失/静态资源缺失/生成失败抛 `PrintTplDenied` |
| `get` | `get(?string $package, string $code): ?array` | 按编号取模板元信息（不含正文）；不存在返回 null |
| `counts` | `counts(): array` | 统计：`{templates, max}`，max=200 为平台模板总数配额 |

> `consumeTicket` 由平台管理端 `printTicket` 端点调用，功能包不应直接使用。

---

## 五、核心语义（双轨归属 / 渲染 / 占位符 / 一次性票据 / 配额）

- **双轨归属（T-1 / AC-6）**：
  - 平台公共模板（`scope=platform`）：所有功能包与平台侧均可渲染；
  - 包级私有模板（`scope=package`，`owner_pkg`=包短名）：仅归属包可渲染，他包渲染抛 `PrintTplDenied`（「无权使用他包私有模板」）；
  - 归属以**契约调用传入的 `$package` 为准**，与数据内容无关（4.3）。
- **业务数据边界（PT-16）**：仅接受 JSON 可表达结构（标量/数组/嵌套对象）；含资源、闭包抛 `PrintTplQuota`；单次数据上限 **1MB**。
- **占位符语法（T-4，v1 最小集）**：
  - `{{order.no}}` 点路径取值，输出自动 HTML 转义；`{{{order.remark}}}` 三括号不转义（仅富文本，超管自担风险）；
  - `{{#each items}}…{{/each}}` 列表循环，体内相对取值、`{{@index}}` 取 0 基序号，**支持嵌套**；
  - `{{#if flag}}…{{else}}…{{/if}}` 条件块（真值：true/非 0 数字/非空字符串/非空数组/非空对象）；v1 限制：if 块不可嵌套于 each/if 内；
  - `{{date 路径 'Y-m-d H:i'}}` 日期格式化（T-4 v1.1）：PHP date 格式，默认 `Y-m-d`，两侧引号可省；strtotime 解析，失败且值为 10 位纯数字按秒级时间戳，解析失败输出原文；
  - `{{money 路径 2}}` 金额格式化（T-4 v1.1）：number_format 千分位，小数位 0-4 钳位、默认 2；非数字输出原文；小数位参数非数字在编译期报错（保存自检即暴露）；
  - `{{pt.page}}` / `{{pt.pages}}` 保留字面，由打印页脚本替换为页码/总页数；
  - 缺失路径一律输出空串，不抛错（容忍数据缺字段，date/money 同语义）。
- **一次性打印票据（T-7）**：`printUrl` 把业务数据服务端落票据，票据 **5 分钟有效、单次使用**，重复消费抛 `PrintTplDenied`；未消费票据随包卸载一并失效。
- **服务端 PDF 生成（T-3 后续项）**：`pdf()` 与浏览器打印共用同一渲染引擎——渲染完整打印文档后，把 `/static/vendor` 前端库引用改写为 `file://` 绝对地址、写入系统临时目录，再调用无头 Edge/Chrome `--headless=new --print-to-pdf` 输出 PDF（@page 纸张、分页脚本、码图绘制全部生效，打印质量一致）；产物约 10 分钟后自动清理；浏览器探测顺序：环境变量 `PRINTTPL_PDF_BROWSER`（指定后必须存在，否则明确报错）→ Windows Edge/Chrome 常见安装路径 → Linux chromium 系列，找不到抛 `PrintTplDenied`。
- **配额（AC-8）**：模板正文上限 **1MB**、平台模板总数上限 **200**；超限抛 `PrintTplQuota`。
- **保存即自检**：保存模板时平台先 `compile` 校验占位符语法，语法错误保存即暴露（`PrintTplDenied`，消息含「占位符语法错误」），不等到业务打印才炸。
- **版本快照（T-5 / PT-18 / AC-5）**：每次保存自动留快照，**保留最近 10 版**；回滚指定版本写回当前并**产生新快照**。

---

## 六、复杂排版能力（跨页 / 页眉页脚页码 / 小计合并 / 布局组件 / 二维码条码 / 图片附件引用）

模板正文为受约束的绝对定位组件模型（可视化编辑器产物，`mode=visual`），渲染管线：`expandTables`（表格组件展开）→ `expandBlocks`（布局与码图组件展开）→ `renderTokens`（占位符填充，含 date/money 格式化）→ `resolveAttachSrcs`（图片附件引用解析）。

- **表格组件**：`<div class="pt-el" data-pt="table" data-bind="items" data-cols='[...]'></div>`（div 恒为空体）；`data-cols` 列定义 `{k:键, t:列名, w:宽, align:对齐, subtotal:是否求和}`。
- **跨页自动分页 + 表头重复（PT-8）**：打印页内嵌分页脚本按纸张可用高度切分行，每页重建真实 `<table>` 并克隆 `<thead>`，表头每页重复；`data-fixed="header|footer"` 的组件每页重复。
- **页眉页脚与页码（PT-8）**：页脚写 `{{pt.page}}/{{pt.pages}}`，打印时逐页替换为「当前页/总页数」。
- **分组小计（PT-8）**：`data-group="分类列"` 按列分组，段尾插入小计行（对 `subtotal:true` 列求和，金额固定两位小数）；组间自动分页。
- **单元格合并（PT-8）**：`data-merge="分类列"` 对组内相邻同值单元格纵向合并（rowspan）。
- **纸张（PT-15）**：A4/A5/Letter 或自定义（30-2000mm），横向/竖向，边距 0-60mm；落为 `@page size`，预览即所得。
- **布局组件（PT-20）**：键值对 / 分栏 / 分组框三类，配置存 `data-*` 属性、div 恒为空体（与表格组件同一约定）：
  - 键值对：`<div data-pt="kv" data-cols="2" data-items='[{"l":"标签","p":"值路径"}]'></div>`，按列数切行渲染 label:value 网格（单据头部信息区）；值路径生成占位符延迟填充，缺失容忍语义与占位符一致；列数钳位 1-4、默认 2；
  - 分栏：`<div data-pt="cols" data-items='[{"w":"50%","html":"栏内容"}]'></div>`，各栏并排 flex 布局；栏宽支持 `%`/`px`/`mm`（正则白名单校验，非法值丢弃后该栏 flex 均分）；栏内容支持 HTML 与占位符；
  - 分组框：`<div data-pt="group" data-title="标题"></div>`，带标题条的边框容器，标题留空只画边框；
  - **不做嵌套子组件模型**：需要视觉层次时用分组框打底、其余组件绝对定位叠放其上；布局组件整块参与分页，不跨页拆分；
  - 空配置渲染兜底提示（「键值对组件未配置条目」/「分栏组件未配置栏」）。
- **二维码/条码组件（PT-21，T-6 v1.1）**：`<div class="pt-el" data-pt="code" data-kind="qr|bar" data-value="内容"></div>`（div 恒为空体）：
  - `data-kind`：`qr`（二维码 QR，默认）/ `bar`（条码 Code128）；`data-value` 支持占位符（如 `{{order.no}}`，渲染时填充，编辑器画布与打印页同契约）；
  - 渲染器只输出带契约属性的空容器，图形由打印页前端 JS 生成（qrcode-generator 生成 QR SVG / JsBarcode 生成 CODE128 SVG），分页前对 `.pt-code` 逐一绘制，分页克隆副本自动携带；
  - SVG 按容器尺寸自适应缩放（QR viewBox scalable、条码按容器高度钳位 24-120px），不拉伸失真；内容为空/图形库缺失/生成失败显示红字提示，不阻塞打印。
- **图片附件引用（PT-19）**：图片组件 `src` 可填完整 URL 或 **`attach://<token>`**（附件托管服务的附件 token）：
  - 渲染时统一解析为 `/attachment/download?token=<token>&image=1`；模板存库保持 `attach://` 原文（与运行环境/域名解耦，换域名不需改模板）；
  - `image=1` 声明图片场景：token 失效/被删/无元数据时由附件服务回退平台默认图片，**打印不破图**；
  - 数据占位符携带 `attach://` 值同样生效：`<img src="{{order.logo}}">` + 数据 `order.logo = "attach://tok9"`；
  - token 从附件托管服务获取：管理后台「附件管理」复制，或功能包经 `Attachment::store(...)` 返回值取得；
  - 匹配不区分大小写（`attach://`/`ATTACH://` 均可）。

---

## 七、包间隔离与生命周期（孤儿冻结 / 接管 / 数据随包）

- **卸载孤儿冻结（AC-7）**：功能包卸载时，其包级私有模板全部冻结（`orphaned_at` 标记），渲染一律抛 `PrintTplDenied`（「已卸载」）；该包未消费票据一并失效。平台公共模板不受影响。
- **重装接管**：重装/升级同名包时平台自动清除孤儿态（`adoptPackage`），模板恢复可用，无需重新配置。
- **数据随包（T-8）**：`PrintTplDataDriver` 按「服务数据随包导出与导入方案」接入：
  - 仅**包级私有模板**随包（`scope=seed` 只导启用中，`scope=all` 导全部含停用）；平台公共模板不随包；
  - 版本快照与打印票据属运行态产物，**不随包**；
  - 导入归属强制为导入方包（`scope` 强制 package），不信任导出文件归属字段；导入即接管，孤儿冻结标记一并清除（AC-7/AC-11）；
  - `purge` 清本包私有模板、版本快照与票据。
- **彻底清理**：包删除时 `purgePackage` 清空其包级模板（区别于卸载的冻结保留）。

---

## 八、使用要点与避坑

- **编号规范**：`用途.版式` 形式（如 `invoice.standard`），小写字母开头，可用 `.` `-` `_`；**创建后不可改**，复制用 `copy` 生成新编号。
- **正文自检包含表格组件**：保存只校验占位符语法；表格 `data-cols` 非法列定义在渲染时才暴露，编辑器产出的属性约定已与服务端对齐，手写正文请沿用可视化编辑器的属性格式（表格与 kv/cols/group 的 data-* 属性同理，属性值必须经实体编码写入）。
- **不要绕过 printUrl 自拼 token**：token 是 32 位随机 hex，服务端校验单次消费与 5 分钟时效；前端拿到 `$pt->printUrl(...)` 返回值直接 `window.open` 即可。
- **大数据走票据不走 GET**：业务数据 1MB 上限在 `printUrl` 落票据时即校验，超限抛 `PrintTplQuota`。
- **`{{pt.page}}` 只在打印页生效**：`render()` 返回的 HTML 中它是字面量，由打印页内嵌脚本在分页时替换；预览正文区域不要依赖它。
- **平台公共模板是共享资产**：所有包可见可用，改动影响面大；包特定版式请建包级私有模板。
- **私有附件的可见性**：`/attachment/download` 对公开附件免登录直出；**私有附件**按当前登录身份裁决（超管或上传者本人）——超管在编辑器/预览里可见，业务终端用户若非上传者会 403 破图；对外分发的单据图片建议上传为**公开附件**后再引用。
- **样例表格无分组不渲染小计**：小计行仅在 `data-group` 非空时按组插入（PT-8 分组小计），整体合计请自行设计合计行或分组。
- **码图在浏览器端生成**：打印页需加载本地静态资源 `/static/vendor/qrcode/qrcode.js` 与 `/static/vendor/barcode/JsBarcode.all.min.js`；`data-value` 中占位符由服务端渲染填充，前端只按最终值画图。
- **PDF 生成依赖服务器可检测到浏览器**：极简/容器环境可用 `PRINTTPL_PDF_BROWSER` 指定 Chrome/Edge 可执行文件绝对路径；产物是系统临时目录中的文件（约 10 分钟清理），拿到 `file` 绝对路径后请尽快下载、归档或发送，勿当作持久存储；生成约需 1-3 秒（无头浏览器冷启动），异步场景建议队列化。

---

## 变更记录

- V_1.4（2026-11-03）：T-3 后续项落地——契约新增 `pdf()`：服务端渲染打印文档并经无头 Edge/Chrome `--print-to-pdf` 生成 PDF（与浏览器打印同一引擎，@page/分页脚本/码图全部生效）；静态资源引用改写 file://、产物落系统临时目录约 10 分钟自动清理、浏览器探测支持 `PRINTTPL_PDF_BROWSER` 环境变量；核心语义与使用要点补对应条目。
- V_1.3（2026-11-03）：v1.1 拍板项落地——新增「格式化占位符」：`{{date 路径 '格式'}}`（PHP date 格式，默认 Y-m-d）与 `{{money 路径 小数位}}`（千分位，0-4 钳位，参数编译期校验）；新增「二维码/条码组件（PT-21）」：`data-pt="code"` 契约（qr/bar、值支持占位符），渲染器输出空容器、打印页前端 JS（qrcode-generator + JsBarcode）生成 SVG，编辑器画布同步预览；使用要点补码图浏览器端生成说明。
- V_1.2（2026-11-03）：新增「布局组件（PT-20）」：键值对（label:value 网格、列数钳位 1-4、值路径占位符延迟填充）/ 分栏（flex 并排、栏宽白名单）/ 分组框（标题条边框容器）三类自绘组件，配置存 data-* 属性、不做嵌套子组件模型；渲染管线补 `expandBlocks` 步骤；编辑器补三组件（面板/画布/属性/序列化）；使用要点补属性实体编码要求。
- V_1.1（2026-11-03）：新增「图片附件引用（PT-19）」：图片组件 src 支持 `attach://<token>` 附件托管服务引用，渲染时解析为附件下载地址并带 `image=1` 兜底；渲染管线补 `resolveAttachSrcs` 步骤；使用要点补私有附件可见性与分组小计说明。
- V_1.0（2026-11-03）：初版。覆盖 render/printUrl/get/counts 契约、双轨归属、占位符 v1 语法、一次性票据、复杂排版（跨页表头/页眉页脚页码/分组小计/单元格合并）、孤儿冻结与接管、数据随包口径。
