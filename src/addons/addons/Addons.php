<?php

namespace think\addons\addons;

use app\BaseController;
use app\common\library\Auth;
use think\App;
use think\facade\Config;
use think\Event;
use think\Lang;
use think\facade\Request;
use think\facade\View;

/**
 * 插件基类控制器
 * @package think\addons
 */
class Addons
{

    // 当前插件操作
    protected $addon = null;
    protected $controller = null;
    protected $action = null;
    // 当前template
    protected $template;
    // 视图路径
    protected $path;
    // 视图模型
    protected $addonsview;
    // 插件配置
    protected $addon_config;
    // 插件信息
    protected $addon_info;
    /**
     * 存储数据
     * @var array
     */
    protected $data = [];

    /**
     * 架构函数
     * @param  App  $app  应用对象
     * @access public
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->name = $this->getName();
        $this->addon_path = $app->getRootPath() . 'addons'. DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR;
        $this->addon_config = "addon_{$this->name}_config";
        $this->addon_info = "addon_{$this->name}_info";

        //移除HTML标签
        //$this->request->filter('trim,strip_tags,htmlspecialchars');

        // 是否自动转换控制器和操作名
        //$convert = Config::get('url_convert');

        //$filter = $convert ? 'strtolower' : 'trim';
        // 处理路由参数
        $param = $this->request->param();

        //$addon = isset($param['addon']) ? $param['addon'] : '';
        $controller = isset($param['controller']) ? $param['controller'] : '';
        //$action = isset($param['action']) ? $param['action'] : '';

        //$this->addon = $addon ? call_user_func($filter, $addon) : '';
        //$this->controller = $controller ? call_user_func($filter, $controller) : 'index';
        //$this->action = $action ? call_user_func($filter, $action) : 'index';
        //if ($controller){
            $this->path=ADDON_PATH . $this->name . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR ;
        //}else{
        //    $this->path=ADDON_PATH . $this->name . DIRECTORY_SEPARATOR  ;
        //}
        ///$path=ADDON_PATH . $this->name . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR ;
        //$path='E:\WebSite\Tools\web\php\phpStudy\PHPTutorial\WWW\develop\Tools\001\Tools\001\htdocs\EasyAdmin\addons\app_demo\app\admin\view\\';
         //重置配置
        //Config::set(['view_path'=>$this->path],'view');
        $this->addonsview = clone View::engine('Think');
        //$this->addonsview->layout(false);
        $this->addonsview->config([
            'view_path' =>$this->path
        ]);

        // 父类的调用必须放在设置模板路径之后
        //parent::__construct($app);
    }

//    protected function _initialize()
//    {
//        // 渲染配置到视图中
//        $config = get_addon_config($this->addon);
//        $this->view->assign("config", $config);
//
//        // 加载系统语言包
//        Lang::load([
//            ADDON_PATH . $this->addon . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $this->request->langset() . EXT,
//        ]);
//
//        // 设置替换字符串
//        $cdnurl = Config::get('site.cdnurl');
//        $this->view->replace('__ADDON__', $cdnurl . "/assets/addons/" . $this->addon);
//
//        $this->auth = Auth::instance();
//        // token
//        $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \think\Cookie::get('token')));
//
//        $path = 'addons/' . $this->addon . '/' . str_replace('.', '/', $this->controller) . '/' . $this->action;
//        // 设置当前请求的URI
//        $this->auth->setRequestUri($path);
//        // 检测是否需要验证登录
//        if (!$this->auth->match($this->noNeedLogin))
//        {
//            //初始化
//            $this->auth->init($token);
//            //检测是否登录
//            if (!$this->auth->isLogin())
//            {
//                $this->error(__('Please login first'), 'index/user/login');
//            }
//            // 判断是否需要验证权限
//            if (!$this->auth->match($this->noNeedRight))
//            {
//                // 判断控制器和方法判断是否有对应权限
//                if (!$this->auth->check($path))
//                {
//                    $this->error(__('You have no permission'));
//                }
//            }
//        }
//        else
//        {
//            // 如果有传递token才验证是否登录状态
//            if ($token)
//            {
//                $this->auth->init($token);
//            }
//        }
//
//        // 如果有使用模板布局
//        if ($this->layout)
//        {
//            $this->view->engine->layout('layout/' . $this->layout);
//        }
//
//        $this->view->assign('user', $this->auth->getUser());
//
//        $site = Config::get("site");
//
//        $upload = \app\common\model\Config::upload();
//
//        // 上传信息配置后
//        Hook::listen("upload_config_init", $upload);
//        Config::set('upload', array_merge(Config::get('upload'), $upload));
//
//        // 加载当前控制器语言包
//        $this->assign('site', $site);
//    }
    
    /**
     * 模板变量赋值
     * @access public
     * @param string|array $name  模板变量
     * @param mixed        $value 变量值
     * @return \think\View
     */
    public function assign($name, $value = null)
    {
        if (is_array($name)) {
            $this->data = array_merge($this->data, $name);
        } else {
            $this->data[$name] = $value;
        }

        return $this;
    }

    /**
     * 加载模板输出
     * @param string $template
     * @param array $vars           模板文件名
     * @return false|mixed|string   模板输出变量
     * @throws \think\Exception
     */
    public function fetch($template = '', $vars = [])
    {
        array_merge($this->data,$vars);
        $this->addonsview->config([
            'view_path' =>$this->path
        ]);
        return $this->addonsview->fetch($template, $this->data);
    }

    /**
     * 默认驱动
     * @return string|null
     */
    public function getDefaultDriver()
    {
        return $this->getConfig('addons');
    }

    /**
     * 加载应用文件和配置
     * @access protected
     * @return void
     */
    public function load(): void
    {
        $addonPath = $this->addon_path;

        if (is_file($addonPath . 'common.php')) {
            include_once $addonPath . 'common.php';
        }

        //include_once $this->thinkPath . 'helper.php';

        $configPath = $addonPath.'config.php';

        $files = [];

        if (is_dir($configPath)) {
            $files = glob($configPath . '*' . $this->configExt);
        }

        foreach ($files as $file) {
            $this->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        if (is_file($addonPath . 'event.php')) {
            $this->loadEvent(include $addonPath . 'event.php');
        }

        if (is_file($addonPath . 'service.php')) {
            $services = include $addonPath . 'service.php';
            foreach ($services as $service) {
                $this->register($service);
            }
        }
    }

    /**
     * 插件基础信息
     * @return array
     */
    final public function getInfo()
    {
        $info = Config::get($this->addon_info, []);
        if ($info) {
            return $info;
        }

        // 文件属性
        $info = $this->info ?? [];
        // 文件配置
        $info_file = addons_type($this->addon_path);
        if (is_file($info_file)) {
            $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            $_info['url'] = addons_url();
            $info = array_merge($_info, $info);
        }
        Config::set($info, $this->addon_info);

        return isset($info) ? $info : [];
    }

    /**
     * 获取配置信息
     * @param string $name 插件名称
     * @param bool $type 是否获取完整配置
     * @return array|mixed
     */
    final public function getConfig($name = '',$type = false)
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $config = Config::get($this->addon_config, []);
        if ($config) {
            return $config;
        }
        $config_file = $this->addon_path . 'config.php';
        if (is_file($config_file)) {
            $temp_arr = (array)include $config_file;
            if ($type) {
                return $temp_arr;
            }
            foreach ($temp_arr as $key => $value) {
                $config[$value['name']] = $value['value'];
            }
            unset($temp_arr);
        }
        Config::set($config, $this->addon_config);

        return $config;
    }

    /**
     * 设置配置数据
     * @param $name
     * @param array $value
     * @return array
     */
    final public function setConfig($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $config = $this->getConfig($name);
        $config = array_merge($config, $value);
        Config::set($name, $config, $this->configRange);
        return $config;
    }

    /**
     * 设置插件信息数据
     * @param $name
     * @param array $value
     * @return array
     */
    final public function setInfo($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $info = $this->getInfo($name);
        $info = array_merge($info, $value);
        Config::set($info, $name);
        return $info;
    }

    /**
     * 获取完整配置列表
     * @param string $name
     * @return array
     */
    final public function getFullConfig($name = '')
    {
        $fullConfigArr = [];
        if (empty($name)) {
            $name = $this->getName();
        }
        $config_file = $this->addon_path . 'config.php';
        if (is_file($config_file)) {
            $fullConfigArr = include $config_file;
        }
        return $fullConfigArr;
    }


    /**
     * 获取插件标识
     * @return mixed|null
     */
    final public function getName()
    {
        $class = get_class($this);
        list(, $name, ) = explode('\\', $class);
        $this->request->addon = $name;

        return $name;
    }

    /**
     * 检查基础配置信息是否完整
     * @return bool
     */
    final public function checkInfo()
    {
        $info = $this->getInfo();
        $info_check_keys = ['name', 'title', 'intro', 'author', 'version', 'state'];
        foreach ($info_check_keys as $value) {
            if (!array_key_exists($value, $info)) {
                return false;
            }
        }
        return true;
    }


    /**
     * 渲染内容输出
     * @access public
     * @param  string $content 模板内容
     * @param  array  $vars    模板输出变量
     * @return mixed
     */
    public function display($content = '}', $vars = [])
    {
        return $this->addonsview->display($content, $vars);
    }


    /**
     * 初始化模板引擎
     * @access public
     * @param  array|string $engine 引擎参数
     * @return $this
     */
    public function engine($engine)
    {
        $this->addonsview->engine($engine);

        return $this;
    }

}
