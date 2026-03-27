<?php

namespace TypechoPlugin\AlgoliaSearch;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;
use Helper;

/**
 * Algolia 高性能实时搜索插件
 * 
 * @package AlgoliaSearch
 * @author 猫东东
 * @version 1.0.0
 * @link https://github.com/xa1st/Typecho-Plugin-Typecho-Plugin-Algolia
 */
class Plugin implements PluginInterface {
    /**
     * 激活插件
     */
    public static function activate() {
        // 绑定文章保存/修改钩子
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finish = [__CLASS__, 'handlePush'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finish = [__CLASS__, 'handlePush'];
        // 绑定文章删除钩子
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->delete = [__CLASS__, 'handleRemove'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->delete = [__CLASS__, 'handleRemove'];
        // 绑定搜索钩子
        \Typecho\Plugin::factory('Widget_Archive')->search = [__CLASS__, 'handleSearch'];
        // 注册全量同步的 Action 路由
        Helper::addAction('algolia-sync', Action::class);
        return _t('插件已激活，请配置 API 信息');
    }

    /**
     * 禁用插件
     */
    public static function deactivate() {
        Helper::removeAction('algolia-sync');
    }

    /**
     * 插件配置面板
     * 创建插件配置表单，包含应用ID、API密钥、索引名称等配置项，并提供全量同步功能
     *
     * @param Form $form 配置表单对象
     * @return void
     */
    public static function config(Form $form) {
        // 应用ID
        $appId = new Text('appId', NULL, '', _t('Application ID'));
        $form->addInput($appId->addRule('required', _t('必须填写 App ID')));
        // API密钥
        $apiKey = new Text('apiKey', NULL, '', _t('Admin API Key'));
        $form->addInput($apiKey->addRule('required', _t('必须填写 Admin API Key')));
        // 索引名称
        $indexName = new Text('indexName', NULL, 'typecho_blog', _t('索引名称'));
        $form->addInput($indexName->addRule('required', _t('必须填写索引名称，默认为 algolia_')));
        // 缓存前缀
        $cachePrefix = new Text('cachePrefix', NULL, 'algolia_', _t('缓存前缀'));
        $form->addInput($cachePrefix->addRule('required', _t('必须填写缓存前缀，默认为 algolia_search_')));
        // 搜索间隔时间
        $interval = new Text('interval', NULL, 5, _t('搜索间隔时间（秒）'));
        $form->addInput($interval->addRule('required', _t('必须填写搜索间隔时间')));
    
        // 批量同步 UI
        echo '<div style="background:#fff; padding:20px; border:1px solid #d9d9d9; margin-top:20px;">';
        echo '<label class="typecho-label">' . _t('全量同步') . '</label>';
        echo '<p class="description">' . _t('点击下方按钮将已有文章推送到 Algolia。同步过程中请勿关闭页面。') . '</p>';
        echo '<button type="button" id="sync-btn" class="btn primary">' . _t('立即开始同步') . '</button>';
        echo '<span id="sync-msg" style="margin-left:10px; color:#467b96;"></span>';
        echo '</div>';
    
        // 注入全量同步 JS
        $syncUrl = Helper::options()->index . '/action/algolia-sync';
        echo "
        <script>
            document.getElementById('sync-btn').onclick = function() {
                if(!confirm('确定要全量同步吗？')) return;
                const btn = this;
                const msg = document.getElementById('sync-msg');
                btn.disabled = true;
    
                function doSync(offset) {
                    msg.innerText = '正在推送从 ' + offset + ' 开始的文章...';
                    fetch('{$syncUrl}?offset=' + offset)
                        .then(res => res.json())
                        .then(data => {
                            if (data.finished) {
                                msg.innerText = '✅ 同步圆满完成！';
                                btn.disabled = false;
                            } else {
                                setTimeout(() => doSync(data.nextOffset), 500);
                            }
                        }).catch(() => {
                            msg.innerText = '❌ 同步失败，请检查配置或网络';
                            btn.disabled = false;
                        });
                }
                doSync(0);
            };
        </script>";
    }

    public static function personalConfig(Form $form) {}

    /**
     * 处理内容推送到 Algolia 搜索引擎
     * 
     * 该方法负责将文章内容处理后推送到 Algolia 搜索服务，包括状态检查、
     * 数据清洗、字段映射等操作
     * 
     * @param object $contents 文章内容对象，包含标题、内容、分类、标签等信息
     * @return void
     */
    public static function handlePush($contents) {
        // 状态过滤：只同步已发布且未加密的内容
        if ($contents->status != 'publish' || !empty($contents->password)) {
            self::handleRemove($contents);
            return;
        }
        // 获取插件配置
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 创建Algolia对象
        $algolia = new Algolia($options->appId, $options->apiKey, $options->indexName);
        // 清理文本：去除 Markdown 符号与 HTML 标签
        $cleanText = str_replace(['', '`', '#', '*'], '', $contents->text);
        // 截取前 2000 个字符
        $excerpt = mb_substr(strip_tags($cleanText), 0, 2000, 'UTF-8');
        // 构造数据
        $data = [
            'title'     => $contents->title,
            'slug'      => $contents->slug,
            'permalink' => $contents->permalink,
            'date'      => $contents->created,
            'category'  => $contents->categories ? $contents->categories[0]['name'] : _t('默认'),
            'tags'      => array_column($contents->tags, 'name'),
            'text'      => $excerpt,
        ];
        // 推送数据
        $algolia->push($contents->cid, $data);
        // 删除缓存
        self::clearSearchCache();
    }

    /**
     * 处理删除操作
     * 
     * 该方法用于从Algolia搜索索引中删除指定的内容
     * 
     * @param object $contents 包含要删除内容信息的对象，必须包含cid属性
     * @return void
     */
    public static function handleRemove($contents) {
        // 获取Algolia搜索插件配置选项
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 初始化Algolia客户端
        $algolia = new Algolia($options->appId, $options->apiKey, $options->indexName);
        // 执行删除操作，根据内容ID从索引中移除对应记录
        $algolia->remove($contents->cid);
        // 删除缓存
        self::clearSearchCache();
    }

    /**
     * 接管原生搜索逻辑（集成 MddCache 缓存）
     * * 该方法通过钩子接管 Typecho 原生搜索，优先从本地缓存读取结果，
     * 穿透后请求 Algolia 云端索引，最终通过主键查询回表，彻底规避 LIKE 扫表。
     * * @param string $keywords 搜索关键词
     * @param \Widget\Archive $archive 归档对象
     * @return bool 返回 true 以截断内核原生搜索逻辑（设置 $hasPushed 为 true）
     */
    public static function handleSearch($keywords, $archive) {
        // 获取插件配置
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 创建缓存实例
        $cache = self::getCache();
        // 频率限制逻辑 
        if ($cache) {
            // 获取 IP
            $ip = $archive->request->getIp();
            // 缓存键
            $ipKey = $options->cachePrefix . '_limit_' . md5($ip);
            // 频率限制锁定
            if ($cache->get($ipKey)) throw new \Typecho\Exception(_t('搜索太频繁了，请稍后再试'));
            // 写入频率限制锁定，时长从配置读取
            $cache->set($ipKey, 1, intval($options->interval));
        }
        // 获取当前分页页码
        $currentPage = $archive->request->get('page', 1);
        // 使用系统设定的每页文章数
        $pageSize = $archive->options->pageSize;
        // 因为只打算缓存当前页的ids，所以键值要带页码
        $cacheKey = $options->cachePrefix . md5($keywords) . '_p' . $currentPage;
        // 尝试从缓存获取 ID 集合
        $cachedData = $cache ? $cache->get($cacheKey) : false;
        // cid集合
        $cids = [];
        // 总数
        $totalFound = 0;
        // 判定缓存是否命中
        if ($cachedData) {
            // 缓存命中，则从缓存中获取数据
            // cids集合
            $cids = $cachedData['cids'];
            // 总数
            $totalFound = $cachedData['total'];
        } else {
            // 未命中缓存，则从Algolia云端索引中获取数据
            $algolia = new Algolia($options->appId, $options->apiKey, $options->indexName);
            // 搜索
            $searchResponse = $algolia->query($keywords, ['attributesToRetrieve' => ['objectID'], 'hitsPerPage' => $pageSize, 'page' => $currentPage - 1]);
            // 搜到了结果
            if ($searchResponse && !empty($searchResponse['hits'])) {
                // id 列表
                $cids = array_column($searchResponse['hits'], 'objectID');
                // 总数
                $totalFound = $searchResponse['nbHits'];
                // 将 ID 列表存入缓存，600秒，其实可以永久，因为改文章的时候会触发删除逻辑
                if ($cache) $cache->set($cacheKey, ['cids' => $cids, 'total' => $totalFound], 600);
            }
        }
        // 执行数据库回表查询（Primary Key 查询，极快）
        if (!empty($cids)) {
            // 从本地数据库中获取数据
            $select = $archive->select()->where('table.contents.cid IN (?)', $cids);
            // 保持 Algolia 的智能排序权重
            $select->order(new \Typecho\Db\Expression('FIELD(table.contents.cid, ' . implode(',', $cids) . ')'));
            // 输出数据
            $archive->setCount($totalFound);
            // 输出数据
            $archive->query($select);
            // 截断内核逻辑
            return true;
        }
        // 兜底：未找到结果
        $archive->setCount(0);
        // 截断内核逻辑
        return true;
    }

    /**
     * 获取缓存实例
     * 这里是私货了，用自己已经写的一个插件来缓存搜索结果
     * @return object|null
     */
    private static function getCache() {
        // 获取插件配置
        return class_exists(\TypechoPlugin\MddCache\Plugin::class) ? \TypechoPlugin\MddCache\Plugin::getCacheInstance() : null;
    }

    /**
     * 清理所有搜索相关的缓存
     * 建议：由于搜索词是无限的，无法精准清理某一个词，
     * 通常的做法是配合缓存标签，或者在文章更新时清理高频搜索词缓存。
    */
    public static function clearSearchCache() {
        $cache = self::getCache();
        if ($cache) {
            // 获取插件配置
            $options = \Widget\Options::alloc()->plugin('AlgoliaSearch');
            // 假设你的 MddCache 支持按前缀清理，或者你简单地清理特定标识
            $cache->flush($options->cachePrefix ?? 'algolia_search_');
        }
    }
}