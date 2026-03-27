<?php

namespace TypechoPlugin\AlgoliaSearch;

use Typecho\Widget;
use Typecho\Db;
use Typecho\Router;
use Widget\Options;

/**
 * 注册动作，执行数据的全量同步
 * @package AlgoliaSearch
 * @author Alex Xu
 */
class Action extends Widget implements \Widget\ActionInterface {

    public function action() {
        // 1. 严格权限检查：确保只有管理员能触发同步
        if (!$this->user->pass('administrator', true)) {
            $this->response->throwJson(['success' => false, 'message' => _t('权限不足')]);
        }
        // 2. 获取分页参数
        $offset = $this->request->get('offset', 0);
        $limit = $this->request->get('limit', 50); // 默认每次处理 50 篇
        // 3. 构造查询：仅获取已发布的文章（不含页面和加密内容）
        $db = Db::get();
        // 3.1 构造查询
        $select = $db->select()->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->where('password IS NULL')
            ->offset($offset)
            ->limit($limit)
            ->order('cid', Db::SORT_ASC);
        // 3.2 查询
        $posts = $db->fetchAll($select);
        // 4. 判定同步是否结束
        if (empty($posts)) $this->response->throwJson(['finished' => true]);
        // 获取插件配置
        $pluginOptions = Options::alloc()->plugin('AlgoliaSearch');
        // 创建Algolia对象
        $algolia = new Algolia($pluginOptions->appId, $pluginOptions->apiKey, $pluginOptions->indexName);
        // 5. 构造批量数据
        $batchData = [];
        foreach ($posts as $post) {
            // 5.1 使用 Typecho 内核方法格式化文章,这样可以自动处理分类、标签以及 permalink
            $item = Widget::widget('Widget_Abstract_Contents')->filter($post);
            // 5.2 清理文本：去除 Markdown 符号与 HTML 标签
            $cleanText = str_replace(['', '`', '#', '*'], '', $item['text']);
            // 5.3 截取前 2000 个字符
            $excerpt = mb_substr(strip_tags($cleanText), 0, 2000, 'UTF-8');
            // 5.4 构造数据
            $batchData[] = [
                'objectID'  => (string)$item['cid'],
                'title'     => $item['title'],
                'slug'      => $item['slug'],
                'permalink' => $item['permalink'],
                'date'      => $item['created'],
                'category'  => $item['categories'] ? $item['categories'][0]['name'] : _t('默认'),
                'tags'      => array_column($item['tags'], 'name'),
                'text'      => $excerpt
            ];
        }
        // 6. 批量提交至 Algolia
        try {
            // 批量提交
            $algolia->pushAll($batchData);
            // 7. 返回进度信息
            $this->response->throwJson(['finished'   => count($posts) < $limit, 'nextOffset' => (int)$offset + (int)$limit]);
        } catch (\Exception $e) {
            $this->response->throwJson(['finished' => true, 'error' => $e->getMessage()]);
        }
    }
}