<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\rbac\RbacStorage;
use linhub\host\services\inbox\InboxService;

/**
 * 站内消息管理端（IM-14 / IM-15）—— admin 应用，仅超管
 *
 *  - GET  /admin/message_admin/records  IM-14 投递记录页
 *  - GET  /admin/message_admin/rows     消息主体分页 JSON（来源包/目标类型/关键字/时间筛选）
 *  - GET  /admin/message_admin/detail   单条消息 + 收件人解析快照与已读状态
 *  - GET  /admin/message_admin/options  来源包选项
 *  - GET  /admin/message_admin/announce IM-15 群发公告页
 *  - GET  /admin/message_admin/targets  用户/角色/用户组选项 JSON
 *  - POST /admin/message_admin/send     发送系统公告（用户/角色/用户组，可混合）
 *
 * 只读排障 + 受控发送：发送固定 source_pkg='platform'、标记系统公告，超管操作留痕。
 */
class MessageAdmin extends AdminController
{
    private function service(): InboxService
    {
        return new InboxService();
    }

    // ===== IM-14 投递记录 =====

    public function records()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('message_admin/records');
    }

    /** JSON：消息主体分页。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['source_pkg', 'target_type', 'keyword', 'date_from', 'date_to', 'user_id'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        // 已读状态过滤依赖 user_id 同时存在（服务层契约）
        $read = trim((string) $req->get('is_read', ''));
        if ($read !== '' && ($filter['user_id'] ?? '') !== '') {
            $filter['is_read'] = (int) $read;
        }

        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));
        $result = $this->service()->paginateMessages($filter, $page, $size);

        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
            'stats'       => $this->service()->stats(),
        ]);
    }

    /** JSON：单条消息与收件人快照。 */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $msgKey = trim((string) $this->request->get('msg_key', ''));
        if ($msgKey === '') {
            return $this->jsonFail('缺少 msg_key');
        }
        $message = $this->service()->messageDetail($msgKey);
        if ($message === null) {
            return $this->jsonFail('消息不存在', 404);
        }
        return $this->jsonOk($message);
    }

    /** JSON：来源包选项（从消息表反查去重）。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $packages = [];
        $result = $this->service()->paginateMessages([], 1, 200);
        foreach ((array) ($result['items'] ?? []) as $row) {
            $pkg = (string) ($row['source_pkg'] ?? '');
            if ($pkg !== '') {
                $packages[$pkg] = true;
            }
        }
        ksort($packages);
        return $this->jsonOk(['source_packages' => array_keys($packages)]);
    }

    // ===== IM-15 群发公告 =====

    public function announce()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('message_admin/announce');
    }

    /** JSON：用户 / 角色 / 用户组选项（来自 RBAC 快照）。 */
    public function targets()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $rbac = $this->bootstrap->rbacStorage();

        $users = [];
        foreach ($rbac->section(RbacStorage::USERS) as $id => $u) {
            $users[] = ['id' => (string) ($u['id'] ?? $id), 'name' => (string) ($u['name'] ?? $id)];
        }
        $roles = [];
        foreach ($rbac->section(RbacStorage::ROLES) as $id => $r) {
            $roles[] = ['id' => (string) ($r['id'] ?? $id), 'name' => (string) ($r['name'] ?? $id)];
        }
        $groups = [];
        foreach ($rbac->section(RbacStorage::GROUPS) as $id => $g) {
            $groups[] = ['id' => (string) ($g['id'] ?? $id), 'name' => (string) ($g['name'] ?? $id)];
        }
        usort($users, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($roles, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($groups, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $this->jsonOk(['users' => $users, 'roles' => $roles, 'groups' => $groups]);
    }

    /** 发送系统公告。 */
    public function send()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $title   = trim((string) $this->request->post('title', ''));
        $content = trim((string) $this->request->post('content', ''));
        $link    = trim((string) $this->request->post('link', ''));
        if ($title === '') {
            return $this->jsonFail('公告标题不能为空');
        }

        $users  = $this->idList($this->request->post('users', []));
        $roles  = $this->idList($this->request->post('roles', []));
        $groups = $this->idList($this->request->post('groups', []));
        if ($users === [] && $roles === [] && $groups === []) {
            return $this->jsonFail('请至少选择一个接收对象（用户/角色/用户组）');
        }

        try {
            $result = $this->service()->send(
                'platform',
                ['title' => $title, 'content' => $content, 'link' => $link],
                ['users' => $users, 'roles' => $roles, 'groups' => $groups],
                ['sender_id' => $gate->id, 'sender_name' => $gate->name !== '' ? $gate->name : $gate->id],
            );
        } catch (\Throwable $e) {
            return $this->jsonFail('发送失败：' . $e->getMessage());
        }

        if ((int) ($result['recipient_count'] ?? 0) === 0) {
            return $this->jsonFail('所选目标未解析到任何接收用户（角色/组可能无成员）');
        }
        $this->audit($gate, 'message.announce.send', (string) ($result['msg_key'] ?? ''), [
            'users' => count($users), 'roles' => count($roles), 'groups' => count($groups),
            'recipient_count' => (int) $result['recipient_count'],
        ]);
        return $this->jsonOk($result, '公告已发送，实际接收 ' . (int) $result['recipient_count'] . ' 人');
    }

    /**
     * 把提交值归一化为去重去空的 id 列表。
     * 接受数组或逗号分隔字符串。
     *
     * @return list<string>
     */
    private function idList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        return array_keys($out);
    }

    /** 超管操作留痕。 */
    private function audit(Identity $identity, string $action, string $target, array $context = []): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, $context + ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }
}
