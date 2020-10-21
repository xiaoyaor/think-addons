<?php

namespace think;

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
    // app 容器
    protected $app;
    // 请求对象
    protected $request;
    // 当前插件标识
    protected $name;
    // 插件路径
    protected $addon_path;
    // 视图模型
    protected $view;
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
        $this->addon_info = "addon_{$this->name}_info";
        $this->addon_config = "addon_{$this->name}_config";

        $this->view = clone View::engine('Think');
        $path=ADDON_PATH . $this->name . DIRECTORY_SEPARATOR  ;
        //$this->view->layout(false);
        //$this->view->layout('layout/default');
        $this->view->config([
            'view_path' =>$path
        ]);

        // 控制器初始化
        $this->initialize();
    }


    // 初始化
    protected function initialize()
    {}

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
        return $this->view->fetch($template, $this->data);
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
        Config::set( $config,$name);
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
        return $this->view->display($content, $vars);
    }

    /**
     * 初始化模板引擎
     * @access public
     * @param  array|string $engine 引擎参数
     * @return $this
     */
    public function engine($engine)
    {
        $this->view->engine($engine);

        return $this;
    }

    //必须实现安装
    public function install(){

    }

    //必须卸载插件方法
    public function uninstall(){

    }

    //必须实现安装
    public function menu(){

    }

    /**
     * 输出信息到控制台
     * @param string $params
     * @param array $data
     * @return false|mixed|string
     * @throws \think\Exception
     */
    public function dashboard($params,$data=[])
    {
        if (!file_exists($this->addon_path.'dashboard.html')){
            return null;
        }
        //$this->view->layout(false);
        $addons =$this->getInfo() ;
        $this->assign(['params' => $params,'addons' => $addons]);
        $this->assign($data);
        return $this->fetch('/dashboard');
    }
}
