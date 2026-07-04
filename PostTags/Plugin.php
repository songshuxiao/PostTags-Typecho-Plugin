<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 文章标签列显示 + 快速添加插件
 *
 * 在后台文章管理列表页新增「标签」列，直观展示每篇文章的标签，
 * 并通过下拉选框快速为文章添加已有标签。
 *
 * @package PostTags
 * @author 智谱清言
 * @version 2.0.0
 * @link https://cloud.szfx.top/typecho/143.html
 */
class PostTags_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('admin/footer.php')->end = array('PostTags_Plugin', 'inject');
        Typecho_Plugin::factory('admin/common.php')->begin = array('PostTags_Plugin', 'handleAjax');
        return _t('插件已激活，请刷新文章管理页面查看效果');
    }

    public static function deactivate()
    {
        return _t('插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $style = new Typecho_Widget_Helper_Form_Element_Select(
            'tag_style',
            array(
                'default' => '默认（灰色标签）',
                'blue'    => '蓝色徽章',
                'green'   => '绿色徽章',
                'orange'  => '橙色徽章',
                'outline' => '镂空边框',
            ),
            'default',
            _t('标签显示样式'),
            _t('选择在文章管理列表中标签的显示样式。')
        );
        $form->addInput($style);

        $maxTags = new Typecho_Widget_Helper_Form_Element_Text(
            'max_tags', NULL, '5',
            _t('最大显示标签数'),
            _t('每篇文章最多显示的标签数量，超出部分显示为「...等N个」。填 0 表示全部显示。')
        );
        $maxTags->input->setAttribute('class', 'mini');
        $form->addInput($maxTags->addRule('isInteger', _t('请填入整数')));

        $position = new Typecho_Widget_Helper_Form_Element_Select(
            'column_position',
            array(
                'after_category' => '分类列之后（推荐）',
                'after_title'    => '标题列之后',
                'last'           => '最后一列',
            ),
            'after_category',
            _t('标签列位置'), _t('选择标签列在表格中的显示位置。')
        );
        $form->addInput($position);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    // ============================================================
    //  AJAX 拦截 (admin/common.php 最早期钩子，无任何输出)
    // ============================================================
    public static function handleAjax()
    {
        if (!isset($_POST['pt_action'])) return;
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, 'manage-posts') === false) return;

        header('Content-Type: application/json; charset=utf-8');

        try {
            $user = Typecho_Widget::widget('Widget_User');
            if (!$user->hasLogin()) self::jsonOut(false, '未登录');

            // 自定义 Token 验证（避免 Widget_Security 跨请求兼容问题）
            $secret   = Typecho_Widget::widget('Widget_Options')->secret ?: '';
            $expected = md5($user->uid . $secret . 'PT_ADD_TAG');
            $token    = isset($_POST['pt_token']) ? $_POST['pt_token'] : '';
            if ($token !== $expected) self::jsonOut(false, '安全验证失败，请刷新页面重试');

            $cid = isset($_POST['cid']) ? intval($_POST['cid']) : 0;
            if ($cid <= 0) self::jsonOut(false, '无效文章');

            $db   = Typecho_Db::get();
            $post = $db->fetchRow($db->select('cid', 'authorId')->from('table.contents')->where('cid = ?', $cid));
            if (!$post) self::jsonOut(false, '文章不存在');

            $isEditor = $user->pass('editor', true);
            if (!$isEditor && intval($user->uid) !== intval($post['authorId'])) {
                self::jsonOut(false, '无权限');
            }

            self::doAddTags($db, $cid);
        } catch (Exception $e) {
            self::jsonOut(false, $e->getMessage());
        }
    }

    private static function doAddTags($db, $cid)
    {
        $mids = isset($_POST['mids']) ? $_POST['mids'] : array();
        if (empty($mids)) self::jsonOut(false, '请选择标签');

        $mids = array_map('intval', (array)$mids);
        $mids = array_filter($mids, function($m) { return $m > 0; });
        if (empty($mids)) self::jsonOut(false, '无效标签');

        foreach ($mids as $mid) {
            $exists = $db->fetchRow($db->select('mid')->from('table.metas')
                ->where('mid = ? AND type = ?', $mid, 'tag'));
            if (!$exists) continue;

            $rel = $db->fetchRow($db->select('cid')->from('table.relationships')
                ->where('cid = ? AND mid = ?', $cid, $mid));
            if ($rel) continue;

            $db->query($db->insert('table.relationships')->rows(array('cid' => $cid, 'mid' => $mid)));
            self::updateMetaCount($db, $mid);
        }

        $allTags = self::getPostTags($db, $cid);
        self::jsonOut(true, '添加成功', array('tags' => $allTags));
    }

    // ============================================================
    //  前端注入
    // ============================================================
    public static function inject()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, 'manage-posts') === false) return;
        if (isset($_POST['pt_action'])) return;

        try { $db = Typecho_Db::get(); } catch (Exception $e) { return; }

        // 1. 文章-标签关联
        $tagsMap = array();
        try {
            $rows = $db->fetchAll($db->select('table.relationships.cid', 'table.metas.name', 'table.metas.slug', 'table.metas.mid')
                ->from('table.metas')->join('table.relationships', 'table.relationships.mid = table.metas.mid', Typecho_Db::LEFT_JOIN)
                ->where('table.metas.type = ?', 'tag'));
            foreach ($rows as $r) {
                if (empty($r['cid'])) continue;
                $tagsMap[strval($r['cid'])][] = array('name' => $r['name'], 'slug' => $r['slug'], 'mid' => intval($r['mid']));
            }
        } catch (Exception $e) {}

        // 2. 所有标签（用于下拉选框）
        $allTags = array();
        try {
            $tagRows = $db->fetchAll($db->select('mid', 'name')->from('table.metas')
                ->where('type = ?', 'tag')->order('name', Typecho_Db::SORT_ASC));
            foreach ($tagRows as $r) $allTags[] = array('mid' => intval($r['mid']), 'name' => $r['name']);
        } catch (Exception $e) {}

        // 3. 作者映射
        $authorMap = array();
        try {
            $aRows = $db->fetchAll($db->select('cid', 'authorId')->from('table.contents'));
            foreach ($aRows as $r) $authorMap[strval($r['cid'])] = intval($r['authorId']);
        } catch (Exception $e) {}

        // 4. 用户信息
        $userUid = 0; $isEditor = false;
        try {
            $user = Typecho_Widget::widget('Widget_User');
            $userUid = intval($user->uid);
            $isEditor = $user->pass('editor', true);
        } catch (Exception $e) {}

        // 5. 配置
        $tagStyle = 'default'; $maxTags = 5; $columnPosition = 'after_category';
        try {
            $po = Typecho_Widget::widget('Widget_Options')->plugin('PostTags');
            if ($po->tag_style) $tagStyle = $po->tag_style;
            if ($po->max_tags !== null) $maxTags = intval($po->max_tags);
            if ($po->column_position) $columnPosition = $po->column_position;
        } catch (Exception $e) {}
        if ($maxTags <= 0) $maxTags = 999;

        // 6. 后台URL
        try { $adminUrl = Typecho_Widget::widget('Widget_Options')->adminUrl; }
        catch (Exception $e) { $adminUrl = '/admin/'; }
        if (substr($adminUrl, -1) !== '/') $adminUrl .= '/';

        // 7. Token
        $csrfToken = '';
        try {
            $secret = Typecho_Widget::widget('Widget_Options')->secret ?: '';
            $csrfToken = md5($userUid . $secret . 'PT_ADD_TAG');
        } catch (Exception $e) {}

        echo self::renderCss($tagStyle);
        echo self::renderJs(
            json_encode($tagsMap, JSON_UNESCAPED_UNICODE),
            json_encode($allTags, JSON_UNESCAPED_UNICODE),
            json_encode($authorMap, JSON_UNESCAPED_UNICODE),
            $maxTags, $columnPosition, $adminUrl, $csrfToken, $userUid, $isEditor
        );
    }

    // ============================================================
    //  辅助
    // ============================================================
    private static function updateMetaCount($db, $mid)
    {
        $r = $db->fetchObject($db->select(array('COUNT(cid)' => 'c'))->from('table.relationships')->where('mid = ?', $mid));
        $db->query($db->update('table.metas')->rows(array('count' => $r ? intval($r->c) : 0))->where('mid = ?', $mid));
    }

    private static function getPostTags($db, $cid)
    {
        $rows = $db->fetchAll($db->select('table.metas.name', 'table.metas.slug', 'table.metas.mid')
            ->from('table.metas')->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ? AND table.metas.type = ?', $cid, 'tag'));
        $tags = array();
        foreach ($rows as $r) $tags[] = array('name' => $r['name'], 'slug' => $r['slug'], 'mid' => intval($r['mid']));
        return $tags;
    }

    private static function jsonOut($ok, $msg, $data = array())
    {
        echo json_encode(array('success' => $ok, 'message' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    //  CSS
    // ============================================================
    private static function renderCss($style)
    {
        $css = <<<'CSS'
<style>
.typecho-list-table .col-tags { max-width: 220px; min-width: 60px; }
.pt-label {
    display: inline-block; margin: 2px 3px; font-size: 12px; line-height: 18px;
    white-space: nowrap; transition: all 0.15s; text-decoration: none;
    padding: 1px 8px; border-radius: 3px;
}
.pt-add {
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; margin: 2px 3px; font-size: 16px; line-height: 1;
    border-radius: 50%; background: #f0f0f0; color: #aaa; cursor: pointer;
    border: 1px dashed #ccc; transition: all 0.15s; user-select: none;
}
.pt-add:hover { background: #e0e0e0; color: #555; border-color: #999; }
.pt-more { font-size: 11px; color: #999; font-style: italic; margin: 0 3px; }
.pt-empty { color: #c0c0c0; user-select: none; }

/* 下拉选择框 */
.pt-dropdown {
    display: none; position: absolute; z-index: 9999;
    width: 220px; background: #fff; border: 1px solid #d0d0d0;
    border-radius: 4px; box-shadow: 0 4px 14px rgba(0,0,0,0.12);
    overflow: hidden;
}
.pt-dropdown.open { display: block; }
.pt-dropdown-search {
    display: block; width: 100%; box-sizing: border-box;
    padding: 6px 8px; border: none; border-bottom: 1px solid #eee;
    font-size: 12px; outline: none;
}
.pt-dropdown-list {
    max-height: 180px; overflow-y: auto; padding: 4px 0; margin: 0;
    list-style: none;
}
.pt-dropdown-list li {
    padding: 5px 10px; font-size: 12px; cursor: pointer; transition: background 0.1s;
}
.pt-dropdown-list li:hover { background: #f5f5f5; }
.pt-dropdown-list li.selected { background: #e8f0fe; color: #1a73e8; }
.pt-dropdown-list li.selected::after { content: ' ✓'; float: right; color: #1a73e8; }
.pt-dropdown-list li.disabled { color: #bbb; cursor: default; }
.pt-dropdown-list li.disabled:hover { background: transparent; }
.pt-dropdown-list li.empty-msg { color: #999; text-align: center; cursor: default; }
.pt-dropdown-list li.empty-msg:hover { background: transparent; }
.pt-dropdown-footer {
    padding: 6px 8px; border-top: 1px solid #eee; text-align: right;
}
.pt-btn {
    font-size: 11px; padding: 3px 12px; border-radius: 3px; cursor: pointer;
    border: 1px solid #ccc; background: #f8f8f8; margin-left: 4px; transition: background 0.15s;
}
.pt-btn:hover { background: #e8e8e8; }
.pt-btn.pt-ok { background: #467b96; color: #fff; border-color: #3a657d; }
.pt-btn.pt-ok:hover { background: #3a657d; }
.pt-btn.pt-ok.loading { opacity: 0.5; pointer-events: none; }

/* Toast */
#pt-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 99999;
    padding: 10px 22px; border-radius: 4px; color: #fff; font-size: 13px;
    opacity: 0; transform: translateY(12px); transition: all 0.3s;
    pointer-events: none; max-width: 360px;
}
#pt-toast.show { opacity: 1; transform: translateY(0); }
#pt-toast.ok { background: #27ae60; }
#pt-toast.err { background: #c0392b; }
</style>
CSS;

        $v = '';
        switch ($style) {
            case 'blue':   $v = '.pt-label{background:#e8f0fe;color:#1a73e8;border:1px solid #d2e3fc;border-radius:10px}.pt-label:hover{background:#d2e3fc}'; break;
            case 'green':  $v = '.pt-label{background:#e6f4ea;color:#137333;border:1px solid #ceead6;border-radius:10px}.pt-label:hover{background:#ceead6}'; break;
            case 'orange': $v = '.pt-label{background:#fef3e0;color:#b06000;border:1px solid #fde0a8;border-radius:10px}.pt-label:hover{background:#fde0a8}'; break;
            case 'outline':$v = '.pt-label{background:transparent;color:#555;border:1px solid #c8c8c8}.pt-label:hover{border-color:#999;color:#333}'; break;
            default:       $v = '.pt-label{background:#f5f5f5;color:#666}.pt-label:hover{background:#e8e8e8;color:#333}'; break;
        }
        return $css . '<style>' . $v . '</style>';
    }

    // ============================================================
    //  JS
    // ============================================================
    private static function renderJs($tagsJson, $allTagsJson, $authorMapJson, $maxTags, $columnPos, $adminUrl, $csrfToken, $userUid, $isEditor)
    {
        $safeAdmin = addslashes($adminUrl);
        $safeToken = addslashes($csrfToken);

        return <<<JS
<script>
(function() {
    'use strict';

    var tagsMap   = {$tagsJson};
    var allTags   = {$allTagsJson};
    var authorMap = {$authorMapJson};
    var maxTags   = {$maxTags};
    var colPos    = '{$columnPos}';
    var adminUrl  = '{$safeAdmin}';
    var token     = '{$safeToken}';
    var uid       = {$userUid};
    var isEditor  = {$isEditor};

    // ===== Toast =====
    var tt = 0;
    function toast(m, c) {
        var el = document.getElementById('pt-toast');
        if (!el) { el = document.createElement('div'); el.id = 'pt-toast'; document.body.appendChild(el); }
        el.textContent = m; el.className = c + ' show';
        clearTimeout(tt); tt = setTimeout(function() { el.className = c; }, 2500);
    }

    function canEdit(cid) { return isEditor || authorMap[cid] === uid; }

    // ===== 表格DOM =====
    var table = document.querySelector('.typecho-list-table');
    if (!table) return;
    var thead = table.querySelector('thead tr');
    if (!thead) return;
    var ths = thead.querySelectorAll('th');

    // 参考列
    var refIdx = -1;
    if (colPos === 'after_category') {
        for (var i = 0; i < ths.length; i++) { var t = ths[i].textContent.trim(); if (t === '分类' || t === 'Category' || t === '分類') { refIdx = i; break; } }
    } else if (colPos === 'after_title') {
        for (var i = 0; i < ths.length; i++) { var t = ths[i].textContent.trim(); if (t === '标题' || t === 'Title' || t === '標題') { refIdx = i; break; } }
    }

    // 缩列宽
    var cg = table.querySelector('colgroup');
    if (cg) {
        var cls = cg.querySelectorAll('col');
        for (var i = 0; i < ths.length && i < cls.length; i++) {
            var x = ths[i].textContent.trim();
            if (x === '作者' || x === 'Author') cls[i].setAttribute('width', '8%');
            if (x === '分类' || x === 'Category' || x === '分類') cls[i].setAttribute('width', '8%');
            if (x === '日期' || x === 'Date') cls[i].setAttribute('width', '10%');
        }
        var nc = document.createElement('col'); nc.setAttribute('width', '14%');
        var cls2 = cg.querySelectorAll('col');
        if (refIdx >= 0 && cls2[refIdx]) cls2[refIdx].after(nc); else cg.appendChild(nc);
    }

    // 表头
    var th = document.createElement('th'); th.textContent = '标签';
    if (refIdx >= 0 && ths[refIdx]) ths[refIdx].after(th); else thead.appendChild(th);

    // ===== 渲染标签内容 =====
    function renderTags(cid) {
        var tags = tagsMap[cid] || [];
        var editable = canEdit(cid);
        var frag = document.createDocumentFragment();

        if (tags.length > 0) {
            var show = Math.min(tags.length, maxTags);
            var rest = tags.length - maxTags;
            for (var i = 0; i < show; i++) {
                var a = document.createElement('a');
                a.className = 'pt-label'; a.textContent = tags[i].name;
                a.href = adminUrl + 'manage-tags.php?mid=' + tags[i].mid;
                frag.appendChild(a);
            }
            if (rest > 0) {
                var m = document.createElement('span'); m.className = 'pt-more';
                m.textContent = '...等' + rest + '个'; frag.appendChild(m);
            }
        } else {
            var e = document.createElement('span'); e.className = 'pt-empty';
            e.textContent = '—'; frag.appendChild(e);
        }

        if (editable) {
            var btn = document.createElement('span');
            btn.className = 'pt-add'; btn.textContent = '+';
            btn.title = '添加标签'; btn.setAttribute('data-cid', cid);
            frag.appendChild(btn);
        }
        return frag;
    }

    // ===== 插入行 =====
    table.querySelectorAll('tbody tr').forEach(function(row) {
        var cb = row.querySelector('input[name="cid[]"]');
        if (!cb) return;
        var cid = cb.value;
        var td = document.createElement('td');
        td.className = 'col-tags'; td.setAttribute('data-cid', cid);
        td.style.position = 'relative';
        td.appendChild(renderTags(cid));
        var tds = row.querySelectorAll('td');
        if (refIdx >= 0 && tds[refIdx]) tds[refIdx].after(td); else row.appendChild(td);
    });

    // ===== 下拉框逻辑 =====
    var activeDropdown = null;
    var activeCid = null;

    function closeDropdown() {
        if (activeDropdown) { activeDropdown.remove(); activeDropdown = null; activeCid = null; }
    }

    function openDropdown(btn) {
        closeDropdown();
        var cid = btn.getAttribute('data-cid');
        activeCid = cid;
        var currentMids = (tagsMap[cid] || []).map(function(t) { return t.mid; });

        // 构建下拉框
        var dd = document.createElement('div');
        dd.className = 'pt-dropdown open';

        // 搜索框
        var search = document.createElement('input');
        search.type = 'text'; search.className = 'pt-dropdown-search';
        search.placeholder = '搜索标签...';
        dd.appendChild(search);

        // 列表
        var ul = document.createElement('ul');
        ul.className = 'pt-dropdown-list';

        var availableTags = allTags.filter(function(t) { return currentMids.indexOf(t.mid) === -1; });

        function renderList(filter) {
            ul.innerHTML = '';
            var filtered = availableTags;
            if (filter) {
                var f = filter.toLowerCase();
                filtered = availableTags.filter(function(t) { return t.name.toLowerCase().indexOf(f) !== -1; });
            }
            if (filtered.length === 0) {
                var li = document.createElement('li');
                li.className = 'empty-msg'; li.textContent = '无可用标签';
                ul.appendChild(li);
            } else {
                filtered.forEach(function(tag) {
                    var li = document.createElement('li');
                    li.textContent = tag.name;
                    li.setAttribute('data-mid', tag.mid);
                    li.addEventListener('click', function() {
                        if (li.classList.contains('selected')) {
                            li.classList.remove('selected');
                        } else {
                            li.classList.add('selected');
                        }
                    });
                    ul.appendChild(li);
                });
            }
        }
        renderList('');

        search.addEventListener('input', function() { renderList(this.value); });
        dd.appendChild(ul);

        // 底部按钮
        var footer = document.createElement('div');
        footer.className = 'pt-dropdown-footer';

        var okBtn = document.createElement('button');
        okBtn.type = 'button'; okBtn.className = 'pt-btn pt-ok'; okBtn.textContent = '添加';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button'; cancelBtn.className = 'pt-btn'; cancelBtn.textContent = '取消';
        cancelBtn.addEventListener('click', function(e) { e.stopPropagation(); closeDropdown(); });

        footer.appendChild(cancelBtn);
        footer.appendChild(okBtn);
        dd.appendChild(footer);

        // 定位到 + 按钮下方
        var td = btn.closest('td');
        td.appendChild(dd);
        activeDropdown = dd;
        search.focus();

        // 确定按钮事件
        okBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var selected = ul.querySelectorAll('li.selected');
            if (selected.length === 0) { search.focus(); return; }
            var mids = [];
            selected.forEach(function(li) { mids.push(parseInt(li.getAttribute('data-mid'))); });

            okBtn.classList.add('loading'); okBtn.textContent = '...';

            var fd = new FormData();
            fd.append('pt_action', 'add');
            fd.append('cid', cid);
            fd.append('pt_token', token);
            mids.forEach(function(m) { fd.append('mids[]', m); });

            fetch(window.location.href.split('#')[0], { method: 'POST', body: fd })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var res;
                    try { res = JSON.parse(txt); } catch (ex) {
                        throw new Error('返回格式错误: ' + txt.substring(0, 80));
                    }
                    if (res.success) {
                        tagsMap[cid] = res.data.tags;
                        refreshCell(cid);
                        closeDropdown();
                        toast('标签添加成功', 'ok');
                    } else {
                        toast(res.message || '添加失败', 'err');
                    }
                })
                .catch(function(err) { toast('请求失败: ' + err.message, 'err'); })
                .finally(function() { okBtn.classList.remove('loading'); okBtn.textContent = '添加'; });
        });
    }

    // 刷新单元格
    function refreshCell(cid) {
        var td = table.querySelector('td.col-tags[data-cid="' + cid + '"]');
        if (!td) return;
        var btn = td.querySelector('.pt-add');
        td.innerHTML = '';
        td.appendChild(renderTags(cid));
    }

    // ===== 全局事件 =====
    table.addEventListener('click', function(e) {
        var btn = e.target.closest('.pt-add');
        if (btn) { e.stopPropagation(); openDropdown(btn); return; }
    });

    // 点击外部关闭
    document.addEventListener('click', function(e) {
        if (activeDropdown && !activeDropdown.contains(e.target)) {
            closeDropdown();
        }
    });

    // Esc关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && activeDropdown) closeDropdown();
    });

})();
</script>
JS;
    }
}
