<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Typecho缓存插件
 *
 * @package TpCache
 * @author 老高
 * @version 0.7
 * @link http://www.phpgao.com
 */

class TpCache_Plugin implements Typecho_Plugin_Interface
{
	public static $cache = null;
	public static $html = null;
	public static $path = null;
	public static $sys_config = null;
	public static $plugin_config = null;
	public static $request = null;
	
	public static $passed = false;

	/**
	 * 激活插件方法,如果激活失败,直接抛出异常
	 *
	 * @access public
	 * @return void
	 * @throws Typecho_Plugin_Exception
	 */
	public static function activate()
	{
		// 在index.php开始, 尝试使用缓存
		Typecho_Plugin::factory('index.php')->begin = array(__CLASS__, 'C');
		// 在index.php结束, 尝试写入缓存
		Typecho_Plugin::factory('index.php')->end = array(__CLASS__, 'S');

        // 编辑页面后更新缓存
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'post_update');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array(__CLASS__, 'post_update');

        //评论
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array(__CLASS__, 'comment_update');
		
		//评论后台
		Typecho_Plugin::factory('Widget_Comments_Edit')->finishDelete = array(__CLASS__, 'comment_update2');
		Typecho_Plugin::factory('Widget_Comments_Edit')->finishEdit = array(__CLASS__, 'comment_update3');
		Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array(__CLASS__, 'comment_update2');

		return '插件安装成功,请设置需要缓存的页面';
	}

	/**
	 * 禁用插件方法,如果禁用失败,直接抛出异常
	 *
	 * @static
	 * @access public
	 * @throws Typecho_Plugin_Exception
	 */
	public static function deactivate()
	{
		try {
			$uninstall_sql = 'DROP TABLE IF EXISTS `%prefix%cache`';
			$db = Typecho_Db::get();
			$prefix = $db->getPrefix();
			$sql = str_replace('%prefix%', $prefix, $uninstall_sql);
			$db->query($sql);
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	/**
	 * 获取插件配置面板
	 *
	 * @access public
	 * @param Typecho_Widget_Helper_Form $form 配置面板
	 * @return void
	 */
	public static function config(Typecho_Widget_Helper_Form $form)
	{

		$list = array(
			'index' => '首页',
			'archive' => '归档',
			'post' => '文章',
			'attachment' => '附件',
			'category' => '分类',
			'tag' => '标签',
			'author' => '作者',
			'search' => '搜索',
			'feed' => 'feed',
			'page' => '页面',
		);
		$element = new Typecho_Widget_Helper_Form_Element_Checkbox('cache_page', $list, array('index', 'post', 'search', 'page', 'author', 'tag'), '需要缓存的页面');
		$form->addInput($element);

		$list = array('关闭', '开启');
		$element = new Typecho_Widget_Helper_Form_Element_Radio('login', $list, 1, '是否对已登录用户失效', '已经录用户不会触发缓存策略');
		$form->addInput($element);

		$list = array('关闭', '开启');
		$element = new Typecho_Widget_Helper_Form_Element_Radio('enable_ssl', $list, '0', '是否支持SSL');
		$form->addInput($element);

		$list = array(
			'0' => '不使用缓存',
			'memcached' => 'Memcached',
			'memcache' => 'Memcache',
			'redis' => 'Redis',
			'mysql' => 'Mysql'
		);
		$element = new Typecho_Widget_Helper_Form_Element_Radio('cache_driver', $list, '0', '缓存驱动');
		$form->addInput($element);

		$element = new Typecho_Widget_Helper_Form_Element_Text('expire', null, '86400', '缓存过期时间', '86400 = 60s * 60m *24h，即一天的秒数');
		$form->addInput($element);

		$element = new Typecho_Widget_Helper_Form_Element_Text('host', null, '127.0.0.1', '主机地址', '主机地址，一般为127.0.0.1');
		$form->addInput($element);

		$element = new Typecho_Widget_Helper_Form_Element_Text('port', null, '11211', '端口号', 'memcache(d)默认为11211，redis默认为6379，其他类型随意填写');
		$form->addInput($element);

		//$list = array('关闭', '开启');
		//$element = new Typecho_Widget_Helper_Form_Element_Radio('is_debug', $list, 0, '是否开启debug');
		//$form->addInput($element);

		$list = array('关闭', '清除所有数据');
		$element = new Typecho_Widget_Helper_Form_Element_Radio('is_clean', $list, 0, '清除所有数据');
		$form->addInput($element);
	}

	public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

	public static function configHandle($config, $is_init)
	{
		if ($config['cache_driver'] != '0') {
			self::initBackend($config['cache_driver']);
			if ($config['is_clean'] == '1') {
				try {
					self::$cache->flush();
				} catch (Exception $e) {
					print $e->getMessage();
				}
				$config['is_clean'] = '0';
			}
		}
		Helper::configPlugin('TpCache', $config);
	}

	/**
	 * 尝试使用缓存
	 */
	public static function C()
	{
		self::initEnv();
		if (!self::preCheck()) return;
		if (!self::initPath()) return;

		try {
			// 获取当前url的缓存
			$data = self::getCache();
			if (!empty($data)) {
				//缓存未过期, 跳过之后的缓存重写入
				if ($data['time'] + self::$plugin_config->expire < time())
					self::$passed = false;
				echo $data['html'];
				die;
			}
		} catch (Exception $e) {
			echo $e->getMessage();
		}
		// 先进行一次刷新
		ob_flush();
	}

	/**
	 * 写入缓存页面
	 */
	public static function S($html = '')
	{
		if (is_null(self::$path) || !self::$passed)
			return;
		if (empty($html))
			$html = ob_get_contents();
		$data = array();
		$data['time'] = time();
		$data['html'] = $html;
		self::setCache($data);
	}

	public static function getCache()
	{
		return unserialize(self::$cache->get(self::$path));
	}

	public static function setCache($data)
	{
		return self::$cache->set(self::$path, serialize($data));
	}

	public static function delCache($path, $rmHome = True)
	{
		self::$cache->delete($path);
		if (rmHome)
			self::$cache->delete('/');
	}

	public static function preCheck($checkPost = True)
	{
		if ($checkPost && self::$request->isPost()) return false;

		if (self::$plugin_config->login && Typecho_Widget::widget('Widget_User')->hasLogin())
			return false;
		if (self::$plugin_config->enable_ssl == '0' && self::$request->isSecure() == true)
			return false;
		if (self::$plugin_config->cache_driver == '0')
			return false;
		self::$passed = true;
		return true;
	}

	public static function initEnv()
	{
		if (is_null(self::$sys_config))
			self::$sys_config = Helper::options();
		if (is_null(self::$plugin_config))
			self::$plugin_config = self::$sys_config->plugin('TpCache');
		if (is_null(self::$request))
			self::$request = new Typecho_Request();
	}

	public static function initPath($pathInfo='')
	{
		if(empty($pathInfo))
			$pathInfo = self::$request->getPathInfo();
		if (!self::needCache($pathInfo)) return false;
		self::$path = $pathInfo;
		return self::initBackend(self::$plugin_config->cache_driver);
	}

	public static function initBackend($backend){
		$class_name = "typecho_$backend";
		require_once 'driver/cache.interface.php';
		require_once "driver/$class_name.class.php";
		self::$cache = call_user_func(array($class_name, 'getInstance'), self::$plugin_config);
		if (is_null(self::$cache))
			return false;
		return true;
	}

	public static function needCache($path)
	{
		//后台数据不缓存
		$pattern = '#^' . __TYPECHO_ADMIN_DIR__ . '#i';
		if (preg_match($pattern, $path)) return false;
		//action动作不缓存
		$pattern = '#^/action#i';
		if (preg_match($pattern, $path)) return false;
		$_routingTable = self::$sys_config->routingTable;
		foreach ($_routingTable[0] as $page => $route) {
			if ($route['widget'] != 'Widget_Archive') continue;
			if (preg_match($route['regx'], $path)) {
				$exclude = array('_year', '_month', '_day', '_page');
				$page = str_replace($exclude, '', $page);
				if (in_array($page, self::$plugin_config->cache_page))
					return true;
			}
		}
		return false;
	}

	public static function post_update($contents, $class)
	{
		if ('publish' != $contents['visibility'] || $contents['created'] > time())
			return;

		self::initEnv();
		if (!self::preCheck(false)) return;

		$type = $contents['type'];
		$routeExists = (NULL != Typecho_Router::get($type));
		if (!$routeExists) {
			self::initPath('#');
			self::delCache(self::$path);
			return;
		}

		$db = Typecho_Db::get();
		$contents['cid'] = $class->cid;
		$contents['categories'] = $db->fetchAll($db->select()->from('table.metas')
			->join('table.relationships', 'table.relationships.mid = table.metas.mid')
			->where('table.relationships.cid = ?', $contents['cid'])
			->where('table.metas.type = ?', 'category')
			->order('table.metas.order', Typecho_Db::SORT_ASC));
		$contents['category'] = urlencode(current(Typecho_Common::arrayFlatten($contents['categories'], 'slug')));
		$contents['slug'] = urlencode($contents['slug']);
		$contents['date'] = new Typecho_Date($contents['created']);
		$contents['year'] = $contents['date']->year;
		$contents['month'] = $contents['date']->month;
		$contents['day'] = $contents['date']->day;
		
		self::initPath(Typecho_Router::url($type, $contents));
		self::delCache(self::$path);
	}

	public static function comment_update($comment)
	{
		self::initEnv();
		if (!self::preCheck(false)) return;
		if (!self::initBackend(self::$plugin_config->cache_driver))
			return;

		// 获取评论的PATH_INFO
		$path_info = self::$request->getPathInfo();
		// 删除最后的 /comment就是需删除的path
		$article_url = preg_replace('/\/comment$/i','',$path_info);

		self::delCache($article_url);
	}
 
	public static function comment_update2($comment = null, $edit)
	{
		self::initEnv();
		self::preCheck(false);
		self::initBackend(self::$plugin_config->cache_driver);

		$perm = stripslashes($edit->parentContent['permalink']);
		$perm = preg_replace('/(https?):\/\//', '', $perm);
		$perm = preg_replace('/'.$_SERVER['HTTP_HOST'].'/', '', $perm);

		self::delCache($perm);
	}
}
