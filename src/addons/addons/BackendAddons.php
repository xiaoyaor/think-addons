<?php
/**
 * +----------------------------------------------------------------------
 * | think-addons [thinkphp6]
 * +----------------------------------------------------------------------
 *  .--,       .--,             | FILE: Addons.php
 * ( (  \.---./  ) )            | AUTHOR: byron
 *  '.__/o   o\__.'             | EMAIL: xiaobo.sun@qq.com
 *     {=  ^  =}                | QQ: 150093589
 *     /       \                | DATETIME: 2019/11/5 14:47
 *    //       \\               |
 *   //|   .   |\\              |
 *   "'\       /'"_.-~^`'-.     |
 *      \  _  /--'         `    |
 *    ___)( )(___               |-----------------------------------------
 *   (((__) (__)))              | 高山仰止,景行行止.虽不能至,心向往之。
 * +----------------------------------------------------------------------
 * | Copyright (c) 2019 http://www.zzstudio.net All rights reserved.
 * +----------------------------------------------------------------------
 */
declare(strict_types=1);

namespace think\addons\addons;

use app\BaseController;
use think\App;
use think\helper\Str;
use think\facade\Config;
use think\facade\View;

/**
 * 插件基类控制器
 * @package think\backendaddons
 */
class BackendAddons
{
    // app 容器
    protected $app;
    /**
     * 存储数据
     * @var array
     */
    protected $data = [];
    // 请求对象
    protected $request;
    // 当前插件标识
    protected $name;
    // 视图路径
    protected $path;
    // 插件路径
    protected $addon_path;
    // 视图模型
    protected $backendview;
    // 插件配置
    protected $addon_config;
    // 插件信息
    protected $addon_info;


    /**
     * 插件构造函数
     * Addons constructor.
     * @param \think\App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->name = $this->getName();
        $this->addon_path = $app->getRootPath() . 'addons'. DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR;
        $this->addon_config = "addon_{$this->name}_config";
        $this->addon_info = "addon_{$this->name}_info";

        //匹配请求网址
        $filter=explode('/',$this->request->pathinfo());
        //if ($filter&&array_key_exists('0',$filter)&&$filter[0] == Config::get('easyadmin.app_url_prefix')){
            $addon=$filter[0];
            $module='admin';
            //$controller=array_key_exists('2',$filter)?$filter[2]:'index';
            //$action=array_key_exists('3',$filter)?$filter[3]:'index';
            $this->path = $app->getRootPath() . 'addons'. DIRECTORY_SEPARATOR . $addon. DIRECTORY_SEPARATOR. 'app' . DIRECTORY_SEPARATOR .$module . DIRECTORY_SEPARATOR. 'view' . DIRECTORY_SEPARATOR ;

        //}

        //定义视图
        $this->backendview = clone View::engine('Think');
        //$this->view->layout(false);
        $this->backendview->config([
            'view_path' =>$this->path
        ]);

        // 控制器初始化
        //$this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        // 加载全局初始化文件
        //$this->load();
    }

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
            if (array_key_exists($name,$this->data)){
                $this->data[$name] = array_merge($this->data[$name], $value);
            }else{
                $this->data[$name] = $value;
            }
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
        $this->backendview->config([
            'view_path' =>$this->path
        ]);
        return $this->backendview->fetch($template, $this->data);
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
        return $this->backendview->display($content, $vars);
    }


    /**
     * 初始化模板引擎
     * @access public
     * @param  array|string $engine 引擎参数
     * @return $this
     */
    public function engine($engine)
    {
        $this->backendview->engine($engine);

        return $this;
    }
}
