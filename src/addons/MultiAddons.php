<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\addons;

use Closure;
use think\App;
use think\exception\HttpException;
use think\facade\Cache;
use think\facade\Config;
use think\module\middleware\Frontend;
use think\Request;
use think\Response;

/**
 * 多应用模式支持
 */
class MultiAddons
{

    /** @var App */
    protected $app;

    /**
     * 应用名称
     * @var string
     */
    protected $name;

    /**
     * 应用名称
     * @var string
     */
    protected $appName = 'index';

    /**
     * 应用名称
     * @var string
     */
    protected $addonsName = 'index';

    /**
     * 应用路径
     * @var string
     */
    protected $path;

    /**
     * 网址路径
     * @var string
     */
    protected $uri;

    /**
     * 应用映射
     * @var string
     */
    protected $map;

    /**
     * 插件映射
     * @var boolean
     */
    protected $addon_map = false;

    /**
     * 禁止访问
     * @var string
     */
    protected $deny;

    /**
     * 域名绑定
     * @var string
     */
    protected $bind;

    /**
     * 子绑定
     * @var string
     */
    protected $subDomain;

    /**
     * 域名
     * @var string
     */
    protected $domain;

    /**
     * 如果插件绑定了规则参数，模块名称(通常)
     * 第1个斜杠分割网址路径参数
     * @var string
     */
    protected $oldfirsturi;

    /**
     * 模块名称(通常)
     * 第1个斜杠分割网址路径参数
     * @var string
     */
    protected $firsturi;

    /**
     * 插件名称(通常)
     * 第2个斜杠分割网址路径参数
     * @var string
     */
    protected $seconduri;

    /**
     * 当前插件模块是否为全局模块
     * 全局模块不受子域名绑定限制
     * 第2个斜杠分割网址路径参数
     * @var boolean
     */
    protected $global = false;


    /**
     * 当前模块是否附加主模块下
     * @var string
     */
    protected $isattach;

    /**
     * 子域名下使用了规则绑定
     * 判断是否是config.php里绑定规则
     * @var string
     */
    protected $isrule;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->name = $this->app->http->getName();
        $this->path = $this->app->http->getPath();

        //网址路径(不包含域名，包含后缀名)
        $this->uri = $this->app->request->pathinfo();
        $this->map  = $this->app->config->get('app.app_map', []);
        $this->deny = $this->app->config->get('app.deny_app_list', []);
        $this->bind = $this->app->config->get('app.domain_bind', []);
        // 获取当前子域名
        $this->subDomain = $this->app->request->subDomain();
        $this->domain    = $this->app->request->host(true);

        //根据路径获取模块和插件名称
        $temp = explode('/', remove_ext($this->uri));
        $this->firsturi  = current($temp)?:'index';
        $this->seconduri = isset($temp[1])?$temp[1]:'index';

        //判断当前插件模块初始信息及是否全局
        $this->global = $this->globalAddonsName();
    }

    /**
     * 多应用解析
     * @access public
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        if (!$this->parseUrl()) {
            return $next($request);
        }

        return $this->app->middleware->pipeline('app')
            ->send($request)
            ->then(function ($request) use ($next) {
                return $next($request);
            });
    }


    /**
     * 解析多应用
     * 4个转到 parseMulti App，3个转到 parseMulti addons
     * @return bool
     */
    protected function parseUrl(): bool
    {
        //域名绑定列表
        $domain_list=Cache::get('domain_list',[]);
        //规则绑定列表
        $rule_list=Cache::get('rule_list',[]);

        $path = $this->uri;
        $map  = $this->map;
        $deny = $this->deny;
        $name = $this->firsturi;

        // 获取当前子域名(子域名和子域名前缀)
        $subDomain = $this->subDomain;
        $domain    = $this->domain;

        //入口文件名称,默认index.php
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        if ($this->name || ($scriptName && !in_array($scriptName, ['index', 'router', 'think']))) {
            $appName = $this->name ?: $scriptName;
            //应用的首页入口,绑定到相应应用
            $this->app->http->setBind();
        } else {
            // 自动多应用识别
            $this->app->http->setBind(false);
            //1============app.domain_bind绑定的子域名【app】============================
            //---有绑定域名信息
            if (!empty($this->bind)) {
                if (isset($this->bind[$domain])||isset($this->bind[$subDomain])||isset($this->bind['*'])) {
                    return $this->parseMultiApp(true);//【系统绑定子域名】
                }
            }
            //2============插件配置文件内部绑定的子域名【addons】=============================
            //---有插件自定义绑定域名信息
            if ($domain_list) {
                foreach ($domain_list as $k=>$val){
                    foreach ($val as $key=>$value){
                        //子域名和插件域名配置的key相同
                        if ($key==$subDomain){

                            //1.获取插件名称
                            $this->get_addonname($value,$subDomain);

                            //2.获取真实插件名称
                            $this->realAddonsName($this->addonsName);

                            return $this->parseMultiAddons(1 , $this->addonsName);//【应用模块绑定映射】
                        }
                    }
                }

            }
            //3============无绑定域名=============================================
            //---无绑定域名信息判断模块映射
            if (!$this->app->http->isBind()) {
                if (isset($map[$name])) {
                    //#####################【app】######################################
                    return $this->parseMultiApp();//【系统模块绑定映射】
                } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                    throw new HttpException(404, 'app not exists:' . $name);
                } elseif ($name && isset($map['*'])) {
                    //######################【app】#####################################
                    return $this->parseMultiApp();//【系统模块绑定映射】
                } else {

                    //######################【addons】#####################################
                    //xxxxsite.php里网站首页自定义绑定
                    $homepage = $this->app->config->get('site.homepage') ?: '';
                    if ($homepage&&!$this->path) {
                        $list = explode('/', $homepage);
                        $this->addonsName=$list[1];
                        $this->addonsName=$this->realAddonsName($this->addonsName);
                        $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                        $con=$this->app->config->load($config,'app_'.$this->addonsName);
                        return $this->parseMultiAddons(2 , $this->addonsName);//【应用模块绑定映射】
                    }

                    //#########################【addons】##################################
                    //xxxxconfig.php里规则绑定
                    foreach ($rule_list as $k=>$val){
                        foreach ($val as $key=>$value){
                            $tmp = $this->oldfirsturi?:$name;
                            if ($key == $tmp){
                                $list = explode('/', $value);
                                $this->addonsName=$this->realAddonsName($list[1]);
                                return $this->parseMultiAddons(3 , $this->addonsName);//【应用模块绑定映射】
                            }
                        }
                    }
                    //######################【app】#####################################
                    //xxx剩余其他
                    return $this->parseMultiApp();
                }
            }
        }
        $this->setApp($appName ?: $defaultApp);
        return true;
    }

    /**
     * 解析【带域名(domain_bind)、应用映射(app_map)、 其他 多应用
     * @param boolean $step  域名绑定(true) 或 应用映射(false) 或 其他(false)
     * @return boolean
     */
    protected function parseMultiApp($step=false): bool
    {
        //插件信息列表
        $data_list=Cache::get('addons_list_data',[]);
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        //网址路径(不包含域名，包含后缀名)
        $path = $this->uri;
        $map  = $this->map;
        $deny = $this->deny;
        $name = $this->firsturi;

        // 获取当前子域名
        $subDomain = $this->subDomain;
        $domain    = $this->domain;

        if ($this->name || ($scriptName && !in_array($scriptName, ['index', 'router', 'think']))) {
            $appName = $this->name ?: $scriptName;
            $this->app->http->setBind();
        } else {
            // 自动多应用识别
            $this->app->http->setBind(false);
            $appName       = null;

            if ($step) {
                /**
                 * [app.domain_bind]有绑定域名信息
                 */
                $this->app->http->setBind();//设置为域名已绑定应用
                //获取模块名称$appname
                if (isset($this->bind[$domain])) {
                    $appName = $this->bind[$domain];
                } elseif (isset($this->bind[$subDomain])) {
                    $appName = $this->bind[$subDomain];
                } elseif (isset($this->bind['*'])) {
                    $appName = $this->bind['*'];
                }

                if ($this->app->http->isBind()) {
                    //获取插件名称
                    if ($this->global) {
                        $name = $this->addonsName = $module = $this->seconduri;
                        $this->addonsName = $module = $this->realAddonsName($this->addonsName);
                    } else {
                        $name = $this->addonsName = $module = $this->firsturi;
                        $this->addonsName = $module = $this->realAddonsName($this->addonsName);
                    }

                    //################首先匹配插件下URL
                    foreach ($data_list as $key => $value) {
                        if ($module == $value['name']) {
                            if (isset($map[$name])) {
                                if ($map[$name] instanceof Closure) {
                                    $result = call_user_func_array($map[$name], [$this->app]);
                                    $appName = $result ?: $name;
                                } else {
                                    $appName = $map[$name];
                                }
                            } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                                throw new HttpException(404, 'app not exists:' . $name);
                            } elseif ($name && isset($map['*'])) {
                                $appName = $map['*'];
                            } else {
                                $appPath = $this->path ?: ADDON_PATH . $this->addonsName . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR;;

                                if (!is_dir($appPath)) {
                                    return $this->express($defaultApp);
                                }
                            }
                            if ($name) {
                                if ($this->addon_map){
                                    $this->set_website('/' . $name,$path,1);
                                }else{
                                    $this->set_website('/' . $name,$path,1);
                                }
                            }
                            $this->setAddons($appName ?: $defaultApp, $this->addonsName);
                            return true;
                        }
                    }

                    //##################其次匹配系统下URL
                    if (isset($map[$name])) {
                        if ($map[$name] instanceof Closure) {
                            $result = call_user_func_array($map[$name], [$this->app]);
                            $appName = $result ?: $name;
                        } else {
                            $appName = $map[$name];
                        }
                    } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                        throw new HttpException(404, 'app not exists:' . $name);
                    } elseif ($name && isset($map['*'])) {
                        $appName = $map['*'];
                    } else {
                        $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;
                        if (!is_dir($appPath)) {
                            return $this->express($defaultApp);
                        }
                    }
                    if ($name) {
                        $this->app->request->setRoot('');
                    }
                    $this->setApp($appName ?: $defaultApp);
                    return true;
                }
            }else{
                /**
                 * [app_map]应用映射或其他
                 */
                //没有绑定子域名，检查映射
                $name = $this->firsturi;
                $this->addonsName = $module = $this->seconduri;
                $this->addonsName = $module = $this->realAddonsName($this->addonsName);

                //############首先匹配插件下URL
                foreach ($data_list as $key=>$value){
                    if ($module==$value['name']){
                        if (!$this->app->http->isBind()) {
                            if (isset($map[$name])) {
                                if ($map[$name] instanceof Closure) {
                                    $result  = call_user_func_array($map[$name], [$this->app]);
                                    $appName = $result ?: $name;
                                } else {
                                    $appName = $map[$name];
                                }
                            } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                                throw new HttpException(404, 'app not exists:' . $name);
                            } elseif ($name && isset($map['*'])) {
                                $appName = $map['*'];
                            } else {

                                $appName = $name ?: $defaultApp;
                                $appPath = $this->path ?: ADDON_PATH.$this->addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;;

                                if (!is_dir($appPath)) {
                                    return $this->express($defaultApp);
                                }
                            }
                            if ($name) {
                                $this->set_website('/' . $name,$path,2);
                            }
                        }
                        $this->setAddons($appName ?: $defaultApp,$this->addonsName);
                        return true;
                    }
                }

                //##############其次匹配系统app下URL
                if (isset($map[$name])) {
                    if ($map[$name] instanceof Closure) {
                        $result  = call_user_func_array($map[$name], [$this->app]);
                        $appName = $result ?: $name;
                    } else {
                        $appName = $map[$name];
                    }
                } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                    throw new HttpException(404, 'app not exists:' . $name);
                } elseif ($name && isset($map['*'])) {
                    $appName = $map['*'];
                } else {

                    $appName = $name ?: $defaultApp;
                    $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;
                    if (!is_dir($appPath)) {
                        return $this->express($defaultApp);
                    }
                }
                if ($name) {
                    $this->set_website('/' . $name,$path,1);
                }
                $this->setApp($appName ?: $defaultApp);
                return true;
            }
        }
    }

    /**
     * 解析addons下的应用的带域名多应用
     * @param int $step 步骤 1：插件内域名绑定；2：site.php首页设置和默认设置；3：规则绑定；
     * @param string $addonsName 应用的名称 $addonsName
     * @return bool
     */
    protected function parseMultiAddons($step=0 , $addonsName = 'index'): bool
    {
        //域名绑定列表
        $domain_list=Cache::get('domain_list',[]);
        //规则绑定列表
        $rule_list=Cache::get('rule_list',[]);

        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        $path = $this->uri;
        $map  = $this->map;
        $deny = $this->deny;
        $name = $this->firsturi;

        // 获取当前子域名
        $subDomain = $this->subDomain;
        $domain    = $this->domain;

        if ($step ==1){
            //1.xxxx解析config.php里域名绑定
            foreach ($domain_list as $k=>$val){
                foreach ($val as $key=>$value){
                    if ($key==$subDomain){
                        //获取模块名称
                        $appName = $this->get_appname($subDomain,explode('/',$value)[0]);

                        $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                        $con=$this->app->config->load($config,'app_'.$this->addonsName);

                        //附加
                        if ($this->isattach){
                            $this->set_website('/' . $name,$path,1);
                        }else{
                            $this->app->request->setRoot('/' . $appName);
                            //排除
                            if ($this->global){
                                /**
                                 * 此处连续去掉2个网址路径带'/'前面的内容并且root为组合
                                 * Root为模块[$appName]和插件名称[$addonsName]组合
                                 * Pathinfo为去掉模块[$appName]和插件名称[$addonsName]，剩下的控制器[controller]和装饰器[action]
                                 */
                                if ($this->isrule){
                                    $this->set_website('/' . $addonsName.'/'. $appName ,$path,1);
                                }else{
                                    $this->set_website('/' . $addonsName.'/'. $appName,$path,2);
                                }
                            }else{
                                if ($this->isrule){
                                    $this->set_website('/' .  $addonsName.'/'. $appName,$path,1);
                                }else{
                                    $this->set_website('/' . $addonsName.'/'. $appName,$path,0);
                                }
                            }
                        }

                        $this->setAddons($appName ?: $defaultApp,$addonsName);
                        return true;
                    }
                }
            }
        }

        if ($step ==3){
            //2.xxxx解析config.php里规则绑定
            foreach ($rule_list as $k=>$val){
                foreach ($val as $key=>$value){
                    $tmp = $this->oldfirsturi?:$name;
                    if ($key==$tmp){
                        $list = explode('/', $value);
                        $this->addonsName=$list[1];
                        $this->addonsName=$this->realAddonsName($this->addonsName);
                        $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                        $con=$this->app->config->load($config,'app_'.$this->addonsName);

                        $appName = $list[0] ?: $defaultApp;
                        if ($name) {
                            $this->set_website('/' . $name,$path,1);
                        }
                        $this->setAddons($appName ?: $defaultApp,$addonsName);
                        return true;
                    }
                }
            }
        }

        if ($step ==2){
            //3.xxxxsite.php里网站首页自定义绑定
            $homepage = $this->app->config->get('site.homepage') ?: '';
            if ($homepage&&!$path) {
                $list = explode('/', $homepage);
                $this->addonsName=$list[1];
                $this->addonsName=$this->realAddonsName($this->addonsName);
                $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                $con=$this->app->config->load($config,'app_'.$this->addonsName);


                if ($homepage&&!$path){
                    $appName = $list[0] ?: $defaultApp;
                    if ($name) {
                        $this->set_website('/' . $name,$path,1);
                    }
                }
                $this->setAddons($appName ?: $defaultApp,$addonsName);
                return true;
            }
        }

        $this->setApp($appName ?: $defaultApp);
        return true;
    }

    /**
     * 设置应用
     * @param string $appName
     */
    protected function setApp(string $appName): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);
        $this->app->request->appName=$appName;

        $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);
        // 设置应用命名空间
        $this->app->setNamespace($this->app->config->get('app.app_namespace') ?: 'app\\' . $appName);

        if (is_dir($appPath)) {
            $this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
            $this->app->http->setRoutePath($this->getRoutePath());

            //加载应用
            $this->loadApp($appName, $appPath);
        }
    }

    /**
     * 设置应用
     * @param string $appName 真实模块名称，非映射
     * @param string $addonsName 插件名称
     */
    protected function setAddons(string $appName,string $addonsName): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);
        $this->app->request->appName=$appName;
        $this->app->request->addonsName=$addonsName;

        $appPath = $this->path ?: ADDON_PATH.$addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);

        $app_namespace='addons\\'.$addonsName.'\\';


        // 设置应用命名空间
        $this->app->setNamespace($app_namespace.'app\\'.$appName ?: 'app\\' . $appName);

        if (is_dir($appPath)) {
            $this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
            $this->app->http->setRoutePath($this->getRoutePath());

            //加载同名应用文件
            $this->loadApp($appName, root_path().'app' . DIRECTORY_SEPARATOR . $appName. DIRECTORY_SEPARATOR);
            //加载插件应用app下的
            $this->loadApp($appName, ADDON_PATH.$addonsName .DIRECTORY_SEPARATOR .'app'. DIRECTORY_SEPARATOR);
            //加载插件应用app下模块下的文件
            $this->loadApp($appName, $appPath);
        }

        //加载插件内部第三方类库
        addon_vendor_autoload($addonsName);
    }

    /**
     * 加载应用文件
     * @param string $appName 应用名
     * @return void
     */
    protected function loadApp(string $appName, string $appPath): void
    {
        if (is_file($appPath . 'common.php')) {
            include_once $appPath . 'common.php';
        }

        $files = [];

        $files = array_merge($files, glob($appPath . 'config' . DIRECTORY_SEPARATOR . '*' . $this->app->getConfigExt()));

        foreach ($files as $file) {
            $this->app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        if (is_file($appPath . 'event.php')) {
            $this->app->loadEvent(include $appPath . 'event.php');
        }

        if (is_file($appPath . 'middleware.php')) {
            $this->app->middleware->import(include $appPath . 'middleware.php', 'app');
        }

        if (is_file($appPath . 'provider.php')) {
            $this->app->bind(include $appPath . 'provider.php');
        }

        // 加载应用默认语言包
        $this->app->loadLangPack($this->app->lang->defaultLangSet());
    }


    /**
     * 获取当前运行入口名称
     * @access protected
     * @codeCoverageIgnore
     * @return string
     */
    protected function getScriptName(): string
    {
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $file = $_SERVER['SCRIPT_FILENAME'];
        } elseif (isset($_SERVER['argv'][0])) {
            $file = realpath($_SERVER['argv'][0]);
        }

        return isset($file) ? pathinfo($file, PATHINFO_FILENAME) : '';
    }

    /**
     * 获取路由目录
     * @access protected
     * @return string
     */
    protected function getRoutePath(): string
    {
        return $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
    }

    /**
     * 异常跳转
     * @access protected
     * @param string $defaultApp 默认app名称
     * @return boolean
     */
    protected function express($defaultApp): bool
    {
        $express = $this->app->config->get('app.app_express', false);
        if ($express) {
            //开启插件快速访问
            if (config('site.default_app')){
                $default_app=explode('/',config('site.default_app'));
                $this->setAddons($default_app[0],$default_app[1]);
            }else{
                //tp6快速访问
                $this->setApp($defaultApp);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * 如果有插件映射获取插件名称
     * @access protected
     * @param string $addonsname 插件名称
     * @return string
     */
    protected function realAddonsName($addonsname): string
    {
        foreach ($this->map as $key => $item) {
            if ($key == $addonsname){
                return $item;
            }
        }

        //插件映射名称
        $map_info=Cache::get('map_info',[]);
        if ($map_info){
            foreach ($map_info as $key => $item) {
                if ($addonsname == $item){
                    $this->addon_map = true;
                    return $key;
                }
            }
        }
        return $addonsname;
    }

    /**
     * 排除插件模块
     * @access protected
     * @return boolean
     */
    protected function globalAddonsName()
    {
        //排除插件模块
        $global_list=Cache::get('global_list',[]);
        //规则绑定列表
        $rule_list=Cache::get('rule_list',[]);
        //判断
        $map  = $this->map;
        if (isset($map[$name])) {
            if ($map[$name] instanceof Closure) {
                $result = call_user_func_array($map[$name], [$this->app]);
                $appName = $result ?: $name;
            } else {
                $appName = $map[$name];
            }
        }

        //规则绑定，还原绑定模块/插件
        $this->isrule = false;
        foreach ($rule_list as $k=>$val){
            foreach ($val as $key=>$value){
                if ($key == $this->firsturi && $this->subDomain == $this->seconduri){
                    $temp = explode('/',$value);
                    if (count($temp) == 2){
                        $this->oldfirsturi = $this->firsturi;
                        $this->firsturi = $temp[0];
                        $this->seconduri = $temp[1];
                        $this->isrule = true;
                        break;
                    }
                }
            }
            if ($this->oldfirsturi){
                break;
            }
        }

        //排除模块，当前模块在子域名下排除
        if ($global_list){
            foreach ($global_list as $key => $item) {
                foreach ($item as $k => $v) {
                    if ($k == $this->firsturi && $v == $this->seconduri){
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * 获取插件名称
     * @param string $value 取值
     * @param string $subdomain 取值
     */
    protected function get_addonname($value,$subdomain)
    {
        //附加插件模块
        $attach_list=Cache::get('attach_list',[]);
        //##############获取插件名称##########
        //1.获取插件名称，判断将同级模块附加到主模块
        $this->isattach = false;
        foreach ($attach_list as $item) {
            foreach ($item as $key2=>$value2){
                if ($value2 ==$subdomain){
                    $list2 = explode('/', $key2);
                    if (count($list2)==2 && $list2[0] == $this->firsturi){
                        $this->addonsName=$list2[1];
                        $this->isattach = true;
                    }
                }
            }
        }

        //2.获取插件名称，判断子域名下排除模块
        if (!$this->isattach){
            $list = explode('/', $value);
            if ($this->global){
                $this->addonsName=$this->seconduri;
            }else{
                $this->addonsName=$list[1];
            }
        }
        return $this->isattach;
    }

    /**
     * 获取模块名称
     * @param string $value 取值
     * @param string $appName 取值
     */
    protected function get_appname($value,$appName)
    {
        //附加插件模块
        $attach_list=Cache::get('attach_list',[]);
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        foreach ($attach_list as $item) {
            foreach ($item as $key2=>$value2){
                if ($value2 ==$value){
                    $list2 = explode('/', $key2);
                    if (count($list2)==2 && $list2[0] == $this->firsturi){
                        $appName=$list2[0];
                    }
                }
            }
        }
        if (!$this->isattach){
            if ($this->global){
                $appName = $this->firsturi;
                $this->app->http->setBind(true);
            }else{
                $appName = $appName ?: $defaultApp;
            }
        }
        return $appName;
    }

    /**
     * 设置网站的根目录和pathinfo
     * @param string $root 根目录
     * @param string $path athinfo
     * @param integer $num 去除几个‘/’
     */
    protected function set_website($root,$path,$num=0)
    {
        $this->app->request->setRoot($root);
        for ($x=0;$x<$num;$x++){
            $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
            $path=$this->app->request->pathinfo();
        }
    }
}