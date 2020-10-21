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
    protected $appName;

    /**
     * 应用名称
     * @var string
     */
    protected $addonsName;

    /**
     * 应用路径
     * @var string
     */
    protected $path;

    /**
     * 应用信息
     * @appName 多应用(模块)名称
     * @addonName addons文件夹下[应用/插件]名称
     * @moduleName 应用下子模块名称
     * 完整实例：
     * http://www.demo.com/admin/demo/dev/sample/index
     * http://'domain'/'appName'/'addonName'/'moduleName'/'controller'/'action'
     * 相对路径：/addons/demo/addons/dev/app/admin/controller/sample.php
     * @var array
    protected $data=[
        'appName'=>'',
        'addonsName'=>'',
        'modulesName'=>''
    ];
    $this->app->request->appName=$appName;
    $this->app->request->addonsName=$addonsName;
    $this->app->request->modulesName=$modulesName;
    */

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->name = $this->app->http->getName();
        $this->path = $this->app->http->getPath();
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
     * @return bool
     */
    protected function parseUrl(): bool
    {
        //插件ini信息列表
        $data_list=Cache::get('addons_list_data',[]);
        //域名绑定列表
        $domain_list=Cache::get('domain_list',[]);
        //规则绑定列表
        $rule_list=Cache::get('rule_list',[]);

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
            $bind = $this->app->config->get('app.domain_bind', []);
            //---有绑定域名信息
            if (!empty($bind)) {
                // 获取当前子域名
                $subDomain = $this->app->request->subDomain();
                $domain    = $this->app->request->host(true);

                if (isset($bind[$domain])||isset($bind[$subDomain])||isset($bind['*'])) {
                    return $this->parseMultiApp();//【系统绑定子域名】
                }
            }
            //---有插件自定义绑定域名信息
            if ($domain_list) {
                // 获取当前子域名
                $subDomain = $this->app->request->subDomain();
                $domain    = $this->app->request->host(true);
                foreach ($domain_list as $k=>$val){
                    foreach ($val as $key=>$value){
                        if ($key==$subDomain){
                            $list = explode('/', $value);
                            $this->addonsName=$list[1];
                            $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                            $this->app->request->addonsName=$this->addonsName;
                            $con=$this->app->config->load($config,'app_'.$this->addonsName);
                            return $this->parseMultiAddons($this->addonsName);//【应用模块绑定映射】
                        }
                    }
                }

                if (isset($bind[$domain])||isset($bind[$subDomain])||isset($bind['*'])) {
                    return $this->parseMultiApp();//【系统绑定子域名】
                }
            }
            //---无绑定域名信息判断模块映射
            if (!$this->app->http->isBind()) {
                $path = $this->app->request->pathinfo();
                $map  = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $name = current(explode('/', $path))?:'index';

                if (isset($map[$name])) {
                    return $this->parseMultiApp();//【系统模块绑定映射】
                } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                    throw new HttpException(404, 'app not exists:' . $name);
                } elseif ($name && isset($map['*'])) {
                    return $this->parseMultiApp();//【系统模块绑定映射】
                } else {

                    //xxxxsite.php里网站首页自定义绑定
                    $homepage = $this->app->config->get('site.homepage') ?: '';
                    if ($homepage&&!$path) {
                        $list = explode('/', $homepage);
                        $this->addonsName=$list[1];
                        $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                        $this->app->request->addonsName=$this->addonsName;
                        $con=$this->app->config->load($config,'app_'.$this->addonsName);
                        return $this->parseMultiAddons($this->addonsName);//【应用模块绑定映射】
                    }

                    //xxxxconfig.php里规则绑定
                    foreach ($rule_list as $k=>$val){
                        foreach ($val as $key=>$value){
                            if ($key==$name){
                                $list = explode('/', $value);
                                $this->addonsName=$list[1];
                                $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                                $this->app->request->addonsName=$this->addonsName;
                                $con=$this->app->config->load($config,'app_'.$this->addonsName);
                                return $this->parseMultiAddons($this->addonsName);//【应用模块绑定映射】
                            }
                        }
                    }

                    //xxx剩余其他
                    return $this->parseMultiApp();
                }
            }
        }
        $this->setApp($appName ?: $defaultApp);
        return true;
    }

    /**
     * 解析【带域名、应用映射】多应用
     * @return bool
     */
    protected function parseMultiApp(): bool
    {
        //插件信息列表
        $data_list=Cache::get('addons_list_data',[]);
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        if ($this->name || ($scriptName && !in_array($scriptName, ['index', 'router', 'think']))) {
            $appName = $this->name ?: $scriptName;
            $this->app->http->setBind();
        } else {
            // 自动多应用识别
            $this->app->http->setBind(false);
            $appName       = null;
            $this->appName = '';

            $bind = $this->app->config->get('app.domain_bind', []);

            //子域名
            if (!empty($bind)) {
                // 获取当前子域名
                $subDomain = $this->app->request->subDomain();
                $domain    = $this->app->request->host(true);

                if (isset($bind[$domain])) {
                    $appName = $bind[$domain];
                    $this->app->http->setBind();
                } elseif (isset($bind[$subDomain])) {
                    $appName = $bind[$subDomain];
                    $this->app->http->setBind();
                } elseif (isset($bind['*'])) {
                    $appName = $bind['*'];
                    $this->app->http->setBind();
                }
            }

            //绑定了子域名
            if ($this->app->http->isBind()) {
                $path = $this->app->request->pathinfo();
                $map  = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $list=explode('/', $path);
                $name = current($list);
                $module = key_exists(0,$list)?$list[0]:'index';
                $this->addonsName=$module;

                //##首先匹配系统插件下常规URL
                foreach ($data_list as $key=>$value){
                    if ($module==$value['name']&&$value['type']=='addon'){
                        $path = $this->app->request->pathinfo();
                        $map  = $this->app->config->get('app.app_map', []);
                        $deny = $this->app->config->get('app.deny_app_list', []);

                        if (strpos($name, '.')) {
                            $name = strstr($name, '.', true);
                        }

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
                            $appPath = $this->path ?: ADDON_PATH.$this->addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;;

                            if (!is_dir($appPath)) {
                                $express = $this->app->config->get('app.app_express', false);
                                if ($express) {
                                    $this->setApp($defaultApp);
                                    return true;
                                } else {
                                    return false;
                                }
                            }
                        }
                        if ($name) {
                            $this->app->request->setRoot('' );
                            $path=$this->app->request->pathinfo();
                            $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                        }
                        $this->setAddons($appName ?: $defaultApp,$this->addonsName);
                        return true;
                    }
                }


                //##其次匹配系统app下常规URL
                $path = $this->app->request->pathinfo();
                $map  = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $name = current(explode('/', $path));

                if (strpos($name, '.')) {
                    $name = strstr($name, '.', true);
                }

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

                    //$appName = $name ?: $defaultApp;
                    $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;
                    //$appPath = $this->path ?: ADDON_PATH.$this->addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;;

                    if (!is_dir($appPath)) {
                        $express = $this->app->config->get('app.app_express', false);
                        if ($express) {
                            $this->setApp($defaultApp);
                            return true;
                        } else {
                            return false;
                        }
                    }
                }
                if ($name) {
                    $this->app->request->setRoot('' );
                    //$this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                    //$path=$this->app->request->pathinfo();
                    //$this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                    //$path=$this->app->request->pathinfo();
                }
                $this->setApp($appName ?: $defaultApp,$this->addonsName);
                return true;
            }else{
                //没有绑定子域名，检查映射
                $path = $this->app->request->pathinfo();
                $map  = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $list=explode('/', $path);
                $name = current($list);
                $module = key_exists(1,$list)?$list[1]:'index';
                $module = remove_ext($module);
                $this->addonsName=$module;
                if (strpos($name, '.')) {
                    $name = strstr($name, '.', true);
                }


                //##首先匹配系统插件下常规URL
                foreach ($data_list as $key=>$value){
                    if ($module==$value['name']&&$value['type']=='addon'){
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
                                //$appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;
                                $appPath = $this->path ?: ADDON_PATH.$this->addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;;

                                if (!is_dir($appPath)) {
                                    $express = $this->app->config->get('app.app_express', false);
                                    if ($express) {
                                        $this->setApp($defaultApp);
                                        return true;
                                    } else {
                                        return false;
                                    }
                                }
                            }
                            if ($name) {
                                $this->app->request->setRoot('/' . $name);
                                $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                                $path=$this->app->request->pathinfo();
                                $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                                //$path=$this->app->request->pathinfo();
                            }
                        }
                        $this->setAddons($appName ?: $defaultApp,$this->addonsName);
                        return true;
                    }
                }


                //##其次匹配系统app下常规URL
//                $path = $this->app->request->pathinfo();
//                $map  = $this->app->config->get('app.app_map', []);
//                $deny = $this->app->config->get('app.deny_app_list', []);
//                $name = current(explode('/', $path));
//
//                if (strpos($name, '.')) {
//                    $name = strstr($name, '.', true);
//                }

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
                    //$appPath = $this->path ?: ADDON_PATH.$this->addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;;

                    if (!is_dir($appPath)) {
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
                }
                if ($name) {
                    $this->app->request->setRoot('/' . $name);
                    //$dd=$this->app->request->pathinfo();
                    $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                    //$path=$this->app->request->pathinfo();
                    //$this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                    //$path=$this->app->request->pathinfo();
                }
                $this->setApp($appName ?: $defaultApp);
                return true;
            }
        }
    }

    /**
     * 解析addons下的应用的带域名多应用
     * @param string $addonsName 应用的名称 $addonsName
     * @return bool
     */
    protected function parseMultiAddons($addonsName): bool
    {
        //插件ini信息列表
        $data_list=Cache::get('addons_list_data',[]);
        //域名绑定列表
        $domain_list=Cache::get('domain_list',[]);
        //规则绑定列表
        $rule_list=Cache::get('rule_list',[]);
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        $path = $this->app->request->pathinfo();
        $map  = $this->app->config->get('app.app_map', []);
        $deny = $this->app->config->get('app.deny_app_list', []);
        $name = current(explode('/', $path))?:'index';
        // 获取当前子域名
        $subDomain = $this->app->request->subDomain();
        $domain    = $this->app->request->host(true);

        //xxxx解析config.php里域名绑定
        foreach ($domain_list as $k=>$val){
            foreach ($val as $key=>$value){
                if ($key==$subDomain){
                    $list = explode('/', $value);
                    $this->addonsName=$list[1];
                    $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                    $this->app->request->addonsName=$this->addonsName;
                    $con=$this->app->config->load($config,'app_'.$this->addonsName);


                    if ($key==$subDomain){
                        $appName = $list[0] ?: $defaultApp;
                        if ($name) {
                            $this->app->request->setRoot('');
                            //$this->app->request->setRoot('/' . $name);
                            //$this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                        }
                    }
                    $this->setAddons($appName ?: $defaultApp,$addonsName);
                    return true;
                }
            }
        }

        //xxxx解析config.php里规则绑定
        foreach ($rule_list as $k=>$val){
            foreach ($val as $key=>$value){
                if ($key==$name){
                    $list = explode('/', $value);
                    $this->addonsName=$list[1];
                    $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                    $this->app->request->addonsName=$this->addonsName;
                    $con=$this->app->config->load($config,'app_'.$this->addonsName);


                    if ($key==$name){
                        $appName = $list[0] ?: $defaultApp;
                        if ($name) {
                            $this->app->request->setRoot('/' . $name);
                            $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                        }
                    }
                    $this->setAddons($appName ?: $defaultApp,$addonsName);
                    return true;
                }
            }
        }

        //xxxxsite.php里网站首页自定义绑定
        $homepage = $this->app->config->get('site.homepage') ?: '';
        if ($homepage&&!$path) {
            $list = explode('/', $homepage);
            $this->addonsName=$list[1];
            $config=ADDON_PATH.$this->addonsName.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
            $this->app->request->addonsName=$this->addonsName;
            $con=$this->app->config->load($config,'app_'.$this->addonsName);


            if ($homepage&&!$path){
                $appName = $list[0] ?: $defaultApp;
                if ($name) {
                    $this->app->request->setRoot('/' . $name);
                    $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                }
            }
            $this->setAddons($appName ?: $defaultApp,$addonsName);
            return true;
        }

        // 自动多应用识别
        $this->app->http->setBind(false);
        $appName       = null;
        $this->appName = '';

        $bind = $this->app->config->get('app.domain_bind', []);

        if (!empty($bind)) {
            // 获取当前子域名
            $subDomain = $this->app->request->subDomain();
            $domain    = $this->app->request->host(true);

            if (isset($bind[$domain])) {
                $appName = $bind[$domain];
                $this->app->http->setBind();
            } elseif (isset($bind[$subDomain])) {
                $appName = $bind[$subDomain];
                $this->app->http->setBind();
            } elseif (isset($bind['*'])) {
                $appName = $bind['*'];
                $this->app->http->setBind();
            }
            $path = $this->app->request->pathinfo();
            $map  = $this->app->config->get('app.app_map', []);
            $deny = $this->app->config->get('app.deny_app_list', []);
            $list=explode('/', $path);
            $name = current($list);
            $module = key_exists(0,$list)?$list[0]:'index';
            $module = remove_ext($module);

            foreach ($data_list as $key=>$value){
                if ($module==$value['name']&&$value['type']=='addon'){
                    $config=ADDON_PATH.$value['name'].DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                    $this->addonsName=$value['name'];
                    $this->app->request->addonsName=$this->addonsName;
                    //$route->rule($value['web']."/:module/[:controller]/[:action]", $execute)->append(['appinfo'=>$value])
                    //   ->middleware(Frontend::class);
                    $con=$this->app->config->load($config,'app_'.$value['name']);
                    $defaultApp = $con['default_app'] ?: 'index';
                    // 自动多应用识别
                    $this->app->http->setBind(false);
                    $appName       = null;
                    $this->appName = '';

                    $bind = $con['domain_bind'];

                    if (!empty($bind)) {
                        // 获取当前子域名
                        $subDomain = $this->app->request->subDomain();
                        $domain    = $this->app->request->host(true);

                        if (isset($bind[$domain])) {
                            $appName = $bind[$domain];
                            $this->app->http->setBind();
                        } elseif (isset($bind[$subDomain])) {
                            $appName = $bind[$subDomain];
                            $this->app->http->setBind();
                        } elseif (isset($bind['*'])) {
                            $appName = $bind['*'];
                            $this->app->http->setBind();
                        }
                    }

                    if (!$this->app->http->isBind()) {
                        $path = $this->app->request->pathinfo();
                        $map  = $this->app->config->get('app.app_map', []);
                        $deny = $this->app->config->get('app.deny_app_list', []);
                        $name = current(explode('/', $path));

                        if (strpos($name, '.')) {
                            $name = strstr($name, '.', true);
                        }

                        $appName = $name ?: $defaultApp;
                        $appPath = $this->path ?: ADDON_PATH.$this->addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;;

                        if (!is_dir($appPath)) {
                            $express = $this->app->config->get('app.app_express', false);
                            if ($express) {
                                $this->setApp($defaultApp);
                                return true;
                            } else {
                                return false;
                            }
                        }

                        if ($name) {
                            $this->app->request->setRoot('/' . $name);
                            $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                        }
                    }
                    $this->setAddons($appName ?: $defaultApp,$module);
                    return true;
                }
            }
        }

        if (!$this->app->http->isBind()) {
            $path = $this->app->request->pathinfo();
            $map  = $this->app->config->get('app.app_map', []);
            $deny = $this->app->config->get('app.deny_app_list', []);
            $name = current(explode('/', $path));

            if (strpos($name, '.')) {
                $name = strstr($name, '.', true);
            }

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
                    $express = $this->app->config->get('app.app_express', false);
                    if ($express) {
                        $this->setApp($defaultApp);
                        return true;
                    } else {
                        return false;
                    }
                }
            }

            if ($name) {
                $this->app->request->setRoot('/' . $name);
                $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
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
     * @param string $appName 模块
     * @param string $addonsName 插件
     */
    protected function setAddons(string $appName,string $addonsName): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);
        $this->app->request->appName=$appName;
        $this->app->request->addonsName=$addonsName;

        $appPath = $this->path ?: ADDON_PATH.$addonsName . DIRECTORY_SEPARATOR.'app'. DIRECTORY_SEPARATOR. $appName . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);

        //$app_namespace=$this->app->config->get('app_'.$this->addonsName.'.app_namespace');
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
}
