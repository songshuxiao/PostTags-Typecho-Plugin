<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 文章标签 + 缩略名快捷编辑插件
 * 
 * 在后台文章管理列表页新增「标签」列，直观展示每篇文章的标签，并通过下拉选框快速为文章添加已有标签。
 * 新增「缩略名」列，并可快速编辑缩略名。
 *
 * @package PostTags
 * @author 智谱清言
 * @version 3.0.0
 * @link https://cloud.szfx.top/typecho/143.html
 */
class PostTags_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('admin/footer.php')->end = array('PostTags_Plugin', 'inject');
        Typecho_Plugin::factory('admin/common.php')->begin = array('PostTags_Plugin', 'handleAjax');
        return _t('插件已激活');
    }

    public static function deactivate() { return _t('插件已禁用'); }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // === 功能开关 ===
        $enableTag = new Typecho_Widget_Helper_Form_Element_Select(
            'enable_tag', 
            array('1' => '启用', '0' => '禁用'),
            '1', _t('标签管理功能开关'), _t('启用后将在文章列表显示标签列，并支持快捷添加。')
        );
        $form->addInput($enableTag);

        $enableSlug = new Typecho_Widget_Helper_Form_Element_Select(
            'enable_slug', 
            array('1' => '启用', '0' => '禁用'),
            '1', _t('缩略名编辑功能开关'), _t('启用后将在文章列表显示缩略名列，并支持快捷修改。')
        );
        $form->addInput($enableSlug);

        // === 标签配置 ===
        $style = new Typecho_Widget_Helper_Form_Element_Select(
            'tag_style', array('default'=>'默认','blue'=>'蓝色','green'=>'绿色','orange'=>'橙色','outline'=>'镂空'),
            'default', _t('标签显示样式')
        );
        $form->addInput($style);

        $maxTags = new Typecho_Widget_Helper_Form_Element_Text('max_tags', NULL, '5', _t('最大显示标签数'));
        $maxTags->input->setAttribute('class', 'mini');
        $maxTags->addRule('isInteger', _t('必须为整数'));
        $form->addInput($maxTags);

        $tagPos = new Typecho_Widget_Helper_Form_Element_Select(
            'tag_column_position', 
            array('after_category'=>'分类后','after_title'=>'标题后','last'=>'末尾'),
            'after_category', _t('标签列位置')
        );
        $form->addInput($tagPos);

        // === 缩略名配置 ===
        $slugPos = new Typecho_Widget_Helper_Form_Element_Select(
            'slug_column_position',
            array('after_title'=>'标题后(推荐)','after_tag'=>'标签后','last'=>'末尾'),
            'after_title', _t('缩略名列位置'), _t('如果标签功能已禁用，"标签后"选项将自动变为"标题后"。')
        );
        $form->addInput($slugPos);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    // ============================================================
    //  AJAX 处理
    // ============================================================
    public static function handleAjax()
    {
        if (!isset($_POST['pt_action'])) return;
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, 'manage-posts') === false) return;

        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $db = Typecho_Db::get();
            $user = Typecho_Widget::widget('Widget_User');
            $options = Typecho_Widget::widget('Widget_Options')->plugin('PostTags');
            
            if (!$user->hasLogin()) self::jsonOut(false, '未登录');

            $secret = Typecho_Widget::widget('Widget_Options')->secret ?: '';
            $expected = md5($user->uid . $secret . 'PT_ACTION_KEY');
            $received = isset($_POST['pt_token']) ? $_POST['pt_token'] : '';
            if ($received !== $expected) self::jsonOut(false, '安全验证失败');

            $cid = isset($_POST['cid']) ? intval($_POST['cid']) : 0;
            if ($cid <= 0) self::jsonOut(false, '文章ID无效');

            $post = $db->fetchRow($db->select('cid', 'authorId', 'slug', 'title')
                ->from('table.contents')->where('cid = ?', $cid));
            if (!$post) self::jsonOut(false, '文章不存在');

            $isEditor = $user->pass('editor', true);
            if (!$isEditor && intval($user->uid) !== intval($post['authorId'])) {
                self::jsonOut(false, '无权限');
            }

            $action = $_POST['pt_action'];
            
            // 功能开关校验
            $enableTag = isset($options->enable_tag) ? $options->enable_tag : '1';
            $enableSlug = isset($options->enable_slug) ? $options->enable_slug : '1';

            if ($action === 'add_tags') {
                if ($enableTag !== '1') self::jsonOut(false, '标签功能已禁用');
                self::processAddTags($db, $cid);
            } elseif ($action === 'update_slug') {
                if ($enableSlug !== '1') self::jsonOut(false, '缩略名功能已禁用');
                self::processUpdateSlug($db, $cid, $post);
            } else {
                self::jsonOut(false, '未知操作');
            }

        } catch (Exception $e) {
            self::jsonOut(false, '异常: ' . $e->getMessage());
        }
    }

    private static function processAddTags($db, $cid)
    {
        $mids = isset($_POST['mids']) ? (array)$_POST['mids'] : array();
        $mids = array_filter(array_map('intval', $mids));
        if (empty($mids)) self::jsonOut(false, '请选择标签');

        foreach ($mids as $mid) {
            $meta = $db->fetchRow($db->select('mid')->from('table.metas')
                ->where('mid = ? AND type = ?', $mid, 'tag'));
            if (!$meta) continue;

            $rel = $db->fetchRow($db->select('cid')->from('table.relationships')
                ->where('cid = ? AND mid = ?', $cid, $mid));
            if ($rel) continue;

            $db->query($db->insert('table.relationships')->rows(array('cid' => $cid, 'mid' => $mid)));
            
            $count = $db->fetchObject($db->select('COUNT(cid) AS num')
                ->from('table.relationships')->where('mid = ?', $mid))->num;
            $db->query($db->update('table.metas')->rows(array('count' => $count))->where('mid = ?', $mid));
        }

        $tags = self::getPostTags($db, $cid);
        self::jsonOut(true, '添加成功', array('tags' => $tags));
    }

    private static function processUpdateSlug($db, $cid, $post)
    {
        $rawSlug = isset($_POST['slug']) ? trim($_POST['slug']) : '';
        
        $newSlug = Typecho_Common::slugName($rawSlug);
        if (empty($newSlug)) $newSlug = Typecho_Common::slugName($post['title']);
        if (empty($newSlug)) $newSlug = 'post-' . $cid;

        $exist = $db->fetchRow($db->select('cid')->from('table.contents')
            ->where('slug = ? AND cid <> ?', $newSlug, $cid)->limit(1));

        if ($exist) {
            $i = 2;
            while ($db->fetchRow($db->select('cid')->from('table.contents')->where('slug = ?', $newSlug . '-' . $i)->limit(1))) {
                $i++;
            }
            $newSlug .= '-' . $i;
        }

        $db->query($db->update('table.contents')->rows(array('slug' => $newSlug))->where('cid = ?', $cid));
        self::jsonOut(true, '缩略名已更新', array('slug' => $newSlug));
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

        $options = Typecho_Widget::widget('Widget_Options')->plugin('PostTags');
        
        // 读取开关
        $enableTag = isset($options->enable_tag) ? $options->enable_tag : '1';
        $enableSlug = isset($options->enable_slug) ? $options->enable_slug : '1';

        // 如果全部禁用，直接返回
        if ($enableTag !== '1' && $enableSlug !== '1') return;

        $tagPos = isset($options->tag_column_position) ? $options->tag_column_position : 'after_category';
        $slugPos = isset($options->slug_column_position) ? $options->slug_column_position : 'after_title';
        
        // 处理依赖：如果标签功能关了，缩略名不能放在标签后
        if ($enableTag !== '1' && $slugPos === 'after_tag') {
            $slugPos = 'after_title';
        }

        $tagStyle = isset($options->tag_style) ? $options->tag_style : 'default';
        $maxTags = isset($options->max_tags) ? intval($options->max_tags) : 5;
        if ($maxTags <= 0) $maxTags = 999;

        $tagsMap = array();
        $allTagsList = array();
        // 只有启用标签才查标签数据
        if ($enableTag === '1') {
            $rows = $db->fetchAll($db->select('table.relationships.cid', 'table.metas.name', 'table.metas.slug', 'table.metas.mid')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid', Typecho_Db::LEFT_JOIN)
                ->where('table.metas.type = ?', 'tag'));
            foreach ($rows as $r) {
                if (empty($r['cid'])) continue;
                $tagsMap[strval($r['cid'])][] = array('mid' => intval($r['mid']), 'name' => $r['name'], 'slug' => $r['slug']);
            }

            $tagRows = $db->fetchAll($db->select('mid', 'name')->from('table.metas')
                ->where('type = ?', 'tag')->order('name', Typecho_Db::SORT_ASC));
            foreach ($tagRows as $t) $allTagsList[] = array('mid' => intval($t['mid']), 'name' => $t['name']);
        }

        $postsMap = array();
        $pRows = $db->fetchAll($db->select('cid', 'authorId', 'slug')->from('table.contents'));
        foreach ($pRows as $p) $postsMap[strval($p['cid'])] = array('author' => intval($p['authorId']), 'slug' => $p['slug']);

        $user = Typecho_Widget::widget('Widget_User');
        $uid = intval($user->uid);
        $isEditor = $user->pass('editor', true);
        $secret = Typecho_Widget::widget('Widget_Options')->secret ?: '';
        $token = md5($uid . $secret . 'PT_ACTION_KEY');
        
        $adminUrl = Typecho_Widget::widget('Widget_Options')->adminUrl;
        if (substr($adminUrl, -1) !== '/') $adminUrl .= '/';

        // 将布尔状态转为 JS 字符串
        $jsEnableTag = ($enableTag === '1') ? 'true' : 'false';
        $jsEnableSlug = ($enableSlug === '1') ? 'true' : 'false';

        echo self::renderCss($tagStyle);
        echo self::renderJs(
            json_encode($tagsMap, JSON_UNESCAPED_UNICODE),
            json_encode($allTagsList, JSON_UNESCAPED_UNICODE),
            json_encode($postsMap, JSON_UNESCAPED_UNICODE),
            $maxTags, $tagPos, $slugPos, $token, $uid, $isEditor, $adminUrl,
            $jsEnableTag, $jsEnableSlug
        );
    }

    private static function getPostTags($db, $cid)
    {
        $rows = $db->fetchAll($db->select('table.metas.name', 'table.metas.slug', 'table.metas.mid')
            ->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ? AND table.metas.type = ?', $cid, 'tag'));
        $list = array();
        foreach ($rows as $r) $list[] = array('mid' => intval($r['mid']), 'name' => $r['name'], 'slug' => $r['slug']);
        return $list;
    }

    private static function jsonOut($ok, $msg, $data=array()) {
        echo json_encode(array('success'=>$ok, 'message'=>$msg, 'data'=>$data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    //  CSS
    // ============================================================
    private static function renderCss($style)
    {
        $css = <<<'CSS'
<style>
.typecho-list-table .col-tags { max-width: 180px; min-width: 60px; }
.typecho-list-table .col-slug { min-width: 120px; }

.pt-tag {
    display: inline-block; margin: 2px 3px; padding: 1px 8px; font-size: 12px; line-height: 18px;
    border-radius: 3px; white-space: nowrap; text-decoration: none; transition: 0.15s;
}
.pt-add-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; margin: 2px; font-size: 16px; line-height: 1;
    border-radius: 50%; background: #f0f0f0; color: #aaa; border: 1px dashed #ccc;
    cursor: pointer; user-select: none;
}
.pt-add-btn:hover { background: #e0e0e0; color: #555; border-color: #999; }

.pt-dd {
    display: none; position: absolute; z-index: 9999;
    width: 200px; background: #fff; border: 1px solid #d0d0d0;
    border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.pt-dd.open { display: block; }
.pt-dd-search {
    width: 100%; box-sizing: border-box; padding: 6px 8px;
    border: none; border-bottom: 1px solid #eee; font-size: 12px; outline: none;
}
.pt-dd-list { max-height: 160px; overflow-y: auto; margin: 0; padding: 4px 0; list-style: none; }
.pt-dd-list li { padding: 5px 10px; font-size: 12px; cursor: pointer; }
.pt-dd-list li:hover { background: #f5f5f5; }
.pt-dd-list li.sel { background: #e8f0fe; color: #1a73e8; }
.pt-dd-list li.sel::after { content: ' ✓'; float: right; }
.pt-dd-ft { padding: 6px 8px; border-top: 1px solid #eee; text-align: right; }
.pt-btn {
    font-size: 11px; padding: 3px 12px; border-radius: 3px; cursor: pointer;
    border: 1px solid #ccc; background: #f8f8f8; margin-left: 4px;
}
.pt-btn-ok { background: #467b96; color: #fff; border-color: #3a657d; }
.pt-btn-ok:hover { background: #3a657d; }
.pt-btn.loading { opacity: 0.5; pointer-events: none; }

.slug-wrap { position: relative; }
.slug-display { 
    font-family: monospace; color: #666; font-size: 12px; cursor: pointer; 
    padding: 2px 4px; border-radius: 2px; display: inline-block; 
}
.slug-display:hover { background: #f0f0f0; color: #333; }
.slug-edit-icon { margin-left: 4px; color: #999; font-size: 10px; }

.slug-editor { 
    display: none; align-items: center; gap: 4px; margin-top: 2px; 
}
.slug-editor.active { display: flex; }
.slug-input {
    font-size: 12px; padding: 2px 6px; border: 1px solid #ccc; border-radius: 3px;
    width: 90px; font-family: monospace; outline: none;
}
.slug-input:focus { border-color: #467b96; }

#pt-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 99999;
    padding: 10px 22px; border-radius: 4px; color: #fff; font-size: 13px;
    opacity: 0; transform: translateY(12px); transition: 0.3s; pointer-events: none;
}
#pt-toast.show { opacity: 1; transform: translateY(0); }
#pt-toast.ok { background: #27ae60; }
#pt-toast.err { background: #c0392b; }
</style>
CSS;
        $v = '';
        switch ($style) {
            case 'blue': $v = '.pt-tag{background:#e8f0fe;color:#1a73e8;border:1px solid #d2e3fc;border-radius:10px}.pt-tag:hover{background:#d2e3fc}'; break;
            case 'green': $v = '.pt-tag{background:#e6f4ea;color:#137333;border:1px solid #ceead6;border-radius:10px}.pt-tag:hover{background:#ceead6}'; break;
            case 'orange': $v = '.pt-tag{background:#fef3e0;color:#b06000;border:1px solid #fde0a8;border-radius:10px}.pt-tag:hover{background:#fde0a8}'; break;
            case 'outline': $v = '.pt-tag{background:transparent;color:#555;border:1px solid #c8c8c8}.pt-tag:hover{border-color:#999}'; break;
            default: $v = '.pt-tag{background:#f5f5f5;color:#666}.pt-tag:hover{background:#e8e8e8}'; break;
        }
        return $css . '<style>' . $v . '</style>';
    }

    // ============================================================
    //  JS (修复：使用字符串变量替代布尔表达式)
    // ============================================================
    private static function renderJs($tagsMap, $allTags, $postsMap, $maxTags, $tagPos, $slugPos, $token, $uid, $isEditor, $adminUrl, $jsEnableTag, $jsEnableSlug)
    {
        $safeAdmin = addslashes($adminUrl);
        return <<<JS
<script>
(function() {
    'use strict';

    var tagsMap   = {$tagsMap};
    var allTags   = {$allTags};
    var postsMap  = {$postsMap};
    var maxTags   = {$maxTags};
    var tagPos    = '{$tagPos}';
    var slugPos   = '{$slugPos}';
    var token     = '{$token}';
    var uid       = {$uid};
    var isEditor  = {$isEditor};
    var adminUrl  = '{$safeAdmin}';

    // 功能开关状态 (字符串转为布尔值)
    var enableTag = {$jsEnableTag};
    var enableSlug = {$jsEnableSlug};

    // --- Utils ---
    var tTimer = 0;
    function toast(m, c) {
        var el = document.getElementById('pt-toast');
        if (!el) { el = document.createElement('div'); el.id = 'pt-toast'; document.body.appendChild(el); }
        el.textContent = m; el.className = c + ' show';
        clearTimeout(tTimer); tTimer = setTimeout(function() { el.className = c; }, 2500);
    }
    function canEdit(cid) { return isEditor || (postsMap[cid] && postsMap[cid].author === uid); }

    // --- Table Setup ---
    var table = document.querySelector('.typecho-list-table');
    if (!table) return;
    var thead = table.querySelector('thead tr');
    var ths = thead.querySelectorAll('th');

    function getOrgIdx(key) {
        for (var i = 0; i < ths.length; i++) {
            var t = ths[i].textContent.trim();
            if (key === 'title' && (t === '标题' || t === 'Title')) return i;
            if (key === 'category' && (t === '分类' || t === 'Category')) return i;
        }
        return -1;
    }

    var cg = table.querySelector('colgroup');
    if (cg) {
        var cols = cg.querySelectorAll('col');
        for (var i = 0; i < ths.length && i < cols.length; i++) {
            var txt = ths[i].textContent.trim();
            if (txt === '作者' || txt === 'Author') cols[i].setAttribute('width', '6%');
            if (txt === '分类' || txt === 'Category') cols[i].setAttribute('width', '6%');
            if (txt === '日期' || txt === 'Date') cols[i].setAttribute('width', '8%');
        }

        function insertColAfter(posKey, width) {
            var newCol = document.createElement('col'); newCol.setAttribute('width', width);
            if (posKey === 'last') { cg.appendChild(newCol); return; }
            
            // 处理依赖：如果要在某功能后，但该功能没开，回退到标题
            if (posKey === 'slug' && !enableSlug) posKey = 'title';
            if (posKey === 'tag' && !enableTag) posKey = 'title';

            var idx = getOrgIdx(posKey);
            var currentCols = cg.querySelectorAll('col');

            if (posKey === 'slug' || posKey === 'tag') {
                 var targetCol = cg.querySelector('.col-' + posKey + '-width');
                 if(targetCol) targetCol.after(newCol);
                 else cg.appendChild(newCol);
                 newCol.classList.add('col-' + posKey + '-width'); // 标记自身以便后续查找
            } else {
                if (idx !== -1 && currentCols[idx]) currentCols[idx].after(newCol);
                else cg.appendChild(newCol);
            }
        }

        // 执行插入列宽
        if (slugPos === 'after_tag' && enableTag && enableSlug) {
            insertColAfter(tagPos.replace('after_', ''), '10%');
            insertColAfter('tag', '10%');
        } else {
            if (enableSlug) insertColAfter(slugPos.replace('after_', ''), '10%');
            if (enableTag) insertColAfter(tagPos.replace('after_', ''), '10%');
        }
    }

    function insertHeaderAfter(posKey, text, cls) {
        var th = document.createElement('th'); th.textContent = text; th.className = cls;
        if (posKey === 'last') { thead.appendChild(th); return; }
        
        if (posKey === 'slug' && !enableSlug) posKey = 'title';
        if (posKey === 'tag' && !enableTag) posKey = 'title';

        var idx = getOrgIdx(posKey);
        if (posKey === 'slug' || posKey === 'tag') {
             var targetTh = thead.querySelector('.col-' + posKey);
             if(targetTh) targetTh.after(th);
             else thead.appendChild(th);
        } else {
            if (idx !== -1 && ths[idx]) ths[idx].after(th);
            else thead.appendChild(th);
        }
    }

    // 插入表头
    if (slugPos === 'after_tag' && enableTag && enableSlug) {
        insertHeaderAfter(tagPos.replace('after_', ''), '标签', 'col-tags');
        insertHeaderAfter('tag', '缩略名', 'col-slug');
    } else {
        if (enableSlug) insertHeaderAfter(slugPos.replace('after_', ''), '缩略名', 'col-slug');
        if (enableTag) insertHeaderAfter(tagPos.replace('after_', ''), '标签', 'col-tags');
    }

    // --- 渲染函数 ---
    function renderTags(cid) {
        var frag = document.createDocumentFragment();
        var tags = tagsMap[cid] || [];
        var editable = canEdit(cid);
        if (tags.length > 0) {
            var show = Math.min(tags.length, maxTags);
            var rest = tags.length - maxTags;
            for (var i = 0; i < show; i++) {
                var a = document.createElement('a');
                a.className = 'pt-tag'; a.textContent = tags[i].name;
                a.href = adminUrl + 'manage-tags.php?mid=' + tags[i].mid;
                frag.appendChild(a);
            }
            if (rest > 0) {
                var m = document.createElement('span'); m.textContent = '...等' + rest + '个';
                m.style.cssText = 'font-size:11px;color:#999;font-style:italic;margin-left:3px;';
                frag.appendChild(m);
            }
        } else {
            var e = document.createElement('span'); e.textContent = '—'; e.style.color = '#ccc'; frag.appendChild(e);
        }
        if (editable) {
            var btn = document.createElement('span'); btn.className = 'pt-add-btn'; btn.textContent = '+';
            btn.setAttribute('data-cid', cid);
            frag.appendChild(btn);
        }
        return frag;
    }

    function renderSlug(cid) {
        var data = postsMap[cid] || { slug: '' };
        var editable = canEdit(cid);
        var wrap = document.createElement('div'); wrap.className = 'slug-wrap';
        var display = document.createElement('span');
        display.className = 'slug-display'; 
        display.textContent = data.slug;
        display.setAttribute('data-cid', cid);
        
        if (editable) {
            display.innerHTML += '<i class="slug-edit-icon">✎</i>';
            display.onclick = function(e) {
                e.stopPropagation();
                var editor = wrap.querySelector('.slug-editor');
                if(editor) {
                    display.style.display = 'none';
                    editor.classList.add('active');
                    editor.querySelector('input').focus();
                }
            };
        }
        wrap.appendChild(display);

        if (editable) {
            var editor = document.createElement('div'); editor.className = 'slug-editor';
            var input = document.createElement('input'); input.type = 'text'; input.className = 'slug-input';
            input.value = data.slug;

            var okBtn = document.createElement('button'); okBtn.type = 'button'; 
            okBtn.className = 'pt-btn pt-btn-ok'; okBtn.textContent = '✓';
            
            okBtn.onclick = function(e) {
                e.stopPropagation();
                var newVal = input.value.trim();
                var oldSlug = data.slug;
                
                okBtn.classList.add('loading');
                
                var fd = new FormData();
                fd.append('pt_action', 'update_slug');
                fd.append('cid', cid);
                fd.append('slug', newVal);
                fd.append('pt_token', token);
                
                fetch(location.href, {method:'POST', body:fd})
                .then(r => r.text()).then(txt => {
                    var res;
                    try { res = JSON.parse(txt); } catch(ex) { throw new Error('返回格式错误'); }
                    if (res.success) {
                        var newSlug = res.data.slug;
                        postsMap[cid].slug = newSlug;
                        
                        wrap.innerHTML = '';
                        wrap.appendChild(renderSlug(cid));
                        
                        // 更新标题列链接
                        var currentRow = table.querySelector('td.col-slug[data-cid="'+cid+'"]')?.closest('tr');
                        if(currentRow) {
                            var links = currentRow.querySelectorAll('a');
                            links.forEach(function(link) {
                                var href = link.getAttribute('href') || '';
                                if (href.indexOf(adminUrl) === 0) return;
                                if (!href) return;
                                if (href.indexOf(oldSlug) !== -1) {
                                    var newHref = href.replace(oldSlug, newSlug);
                                    link.setAttribute('href', newHref);
                                    if (link.textContent.trim() === oldSlug) link.textContent = newSlug;
                                }
                            });
                        }
                        
                        toast('缩略名已更新', 'ok');
                    } else {
                        toast(res.message || '更新失败', 'err');
                        okBtn.classList.remove('loading');
                    }
                }).catch(err => {
                    toast(err.message, 'err');
                    okBtn.classList.remove('loading');
                });
            };

            var cancelBtn = document.createElement('button'); cancelBtn.type = 'button';
            cancelBtn.className = 'pt-btn'; cancelBtn.textContent = '×';
            
            cancelBtn.onclick = function(e) {
                e.stopPropagation();
                editor.classList.remove('active');
                display.style.display = '';
                input.value = postsMap[cid].slug;
            };

            editor.appendChild(input); editor.appendChild(okBtn); editor.appendChild(cancelBtn);
            wrap.appendChild(editor);
        }
        
        return wrap;
    }

    // --- 行处理 ---
    var rows = table.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
        var cb = row.querySelector('input[name="cid[]"]');
        if (!cb) return;
        var cid = cb.value;
        var originalTds = row.querySelectorAll('td');

        function insertTdAfter(posKey, tdElement) {
            if (posKey === 'slug' && !enableSlug) posKey = 'title';
            if (posKey === 'tag' && !enableTag) posKey = 'title';

            if (posKey === 'last') { row.appendChild(tdElement); return; }
            
            if (posKey === 'slug' || posKey === 'tag') {
                var targetTd = row.querySelector('.col-' + posKey);
                if(targetTd) targetTd.after(tdElement);
                else row.appendChild(tdElement);
            } else {
                var idx = getOrgIdx(posKey);
                if (idx !== -1 && originalTds[idx]) originalTds[idx].after(tdElement);
                else row.appendChild(tdElement);
            }
        }

        // 创建单元格
        var slugTd = null, tagTd = null;
        if (enableSlug) {
            slugTd = document.createElement('td'); slugTd.className = 'col-slug'; slugTd.setAttribute('data-cid', cid);
            slugTd.appendChild(renderSlug(cid));
        }
        if (enableTag) {
            tagTd = document.createElement('td'); tagTd.className = 'col-tags'; tagTd.setAttribute('data-cid', cid);
            tagTd.style.position = 'relative';
            tagTd.appendChild(renderTags(cid));
        }

        // 执行插入
        if (slugPos === 'after_tag' && enableTag && enableSlug) {
            insertTdAfter(tagPos.replace('after_', ''), tagTd);
            insertTdAfter('tag', slugTd);
        } else {
            if (enableSlug) insertTdAfter(slugPos.replace('after_', ''), slugTd);
            if (enableTag) insertTdAfter(tagPos.replace('after_', ''), tagTd);
        }
    });

    // --- 标签下拉逻辑 (仅启用时绑定) ---
    if (enableTag) {
        var activeDD = null;
        function closeDD() { if(activeDD) { activeDD.remove(); activeDD = null; } }

        table.addEventListener('click', function(e) {
            var btn = e.target.closest('.pt-add-btn');
            if (!btn) return;
            e.stopPropagation(); closeDD();

            var cid = btn.getAttribute('data-cid');
            var currentMids = (tagsMap[cid] || []).map(function(t){ return t.mid; });
            var available = allTags.filter(function(t){ return currentMids.indexOf(t.mid) === -1; });

            var dd = document.createElement('div'); dd.className = 'pt-dd open';
            var search = document.createElement('input'); search.type='text'; search.className='pt-dd-search'; search.placeholder='搜索...';
            var ul = document.createElement('ul'); ul.className='pt-dd-list';
            
            function renderList(f) {
                ul.innerHTML = '';
                var list = f ? available.filter(function(t){ return t.name.toLowerCase().indexOf(f.toLowerCase())!==-1; }) : available;
                if(!list.length) { var li=document.createElement('li'); li.textContent='无可用标签'; li.className='empty-msg'; ul.appendChild(li); }
                else list.forEach(function(t) {
                    var li=document.createElement('li'); li.textContent=t.name; li.setAttribute('data-mid', t.mid);
                    li.onclick=function(){ li.classList.toggle('sel'); };
                    ul.appendChild(li);
                });
            }
            renderList('');
            search.oninput=function(){ renderList(this.value); };
            
            var ft = document.createElement('div'); ft.className='pt-dd-ft';
            var cancel = document.createElement('button'); cancel.type='button'; cancel.className='pt-btn'; cancel.textContent='取消';
            cancel.onclick=function(e){ e.stopPropagation(); closeDD(); };
            
            var ok = document.createElement('button'); ok.type='button'; ok.className='pt-btn pt-btn-ok'; ok.textContent='添加';
            ok.onclick = function(e) {
                e.stopPropagation();
                var sel = ul.querySelectorAll('li.sel');
                if(!sel.length) { search.focus(); return; }
                var mids = []; sel.forEach(function(li){ mids.push(parseInt(li.getAttribute('data-mid'))); });
                
                ok.classList.add('loading');
                var fd = new FormData();
                fd.append('pt_action', 'add_tags'); fd.append('cid', cid); fd.append('pt_token', token);
                mids.forEach(function(m){ fd.append('mids[]', m); });
                
                fetch(location.href, {method:'POST', body:fd})
                .then(r=>r.text()).then(txt=>{
                    var res; try{ res=JSON.parse(txt); } catch(ex){ throw new Error('解析错误'); }
                    if(res.success) {
                        tagsMap[cid] = res.data.tags;
                        var td = table.querySelector('td.col-tags[data-cid="'+cid+'"]');
                        if(td){ td.innerHTML = ''; td.appendChild(renderTags(cid)); }
                        closeDD(); toast('标签已添加', 'ok');
                    } else toast(res.message, 'err');
                }).catch(err=>toast(err.message,'err')).finally(()=>ok.classList.remove('loading'));
            };

            dd.appendChild(search); dd.appendChild(ul);
            ft.appendChild(cancel); ft.appendChild(ok); dd.appendChild(ft);
            btn.closest('td').appendChild(dd);
            activeDD = dd; search.focus();
        });

        document.addEventListener('click', function(e) { if(activeDD && !activeDD.contains(e.target)) closeDD(); });
        document.addEventListener('keydown', function(e) { if(e.key==='Escape') closeDD(); });
    }

})();
</script>
JS;
    }
}
