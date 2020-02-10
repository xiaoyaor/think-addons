<?php

namespace think\addons;

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
class Controller extends BaseController
{

    // 当前插件操作
    protected $addon = null;
    protected $controller = null;
    protected $action = null;
    // 当前template
    protected $template;
    // 视图模型
    protected $view;

    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = ['*'];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = ['*'];

    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;

    /**
     * 布局模板
     * @var string
     */
    protected $layout = null;

    /**
     * 架构函数
     * @param  App  $app  应用对象
     * @access public
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        //移除HTML标签
        $this->request->filter('trim,strip_tags,htmlspecialchars');

        // 是否自动转换控制器和操作名
        $convert = Config::get('url_convert');

        $filter = $convert ? 'strtolower' : 'trim';
        // 处理路由参数
        $param = $this->request->param();

        $addon = isset($param['addon']) ? $param['addon'] : '';
        $controller = isset($param['controller']) ? $param['controller'] : '';
        $action = isset($param['action']) ? $param['action'] : '';

        $this->addon = $addon ? call_user_func($filter, $addon) : '';
        $this->controller = $controller ? call_user_func($filter, $controller) : 'index';
        $this->action = $action ? call_user_func($filter, $action) : 'index';

        $path=ADDON_PATH . $this->addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR ;
         //重置配置
        Config::set(['view_path'=>$path],'view');
        $this->view = clone View::engine('Think');
        $this->view->layout(false);
        $this->view->config([
            'view_path' =>$path
        ]);

        // 父类的调用必须放在设置模板路径之后
        parent::__construct($app);
    }

    protected function _initialize()
    {
        // 渲染配置到视图中
        $config = get_addon_config($this->addon);
        $this->view->assign("config", $config);

        // 加载系统语言包
        Lang::load([
            ADDON_PATH . $this->addon . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $this->request->langset() . EXT,
        ]);

        // 设置替换字符串
        $cdnurl = Config::get('site.cdnurl');
        $this->view->replace('__ADDON__', $cdnurl . "/assets/addons/" . $this->addon);

        $this->auth = Auth::instance();
        // token
        $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \think\Cookie::get('token')));

        $path = 'addons/' . $this->addon . '/' . str_replace('.', '/', $this->controller) . '/' . $this->action;
        // 设置当前请求的URI
        $this->auth->setRequestUri($path);
        // 检测是否需要验证登录
        if (!$this->auth->match($this->noNeedLogin))
        {
            //初始化
            $this->auth->init($token);
            //检测是否登录
            if (!$this->auth->isLogin())
            {
                $this->error(__('Please login first'), 'index/user/login');
            }
            // 判断是否需要验证权限
            if (!$this->auth->match($this->noNeedRight))
            {
                // 判断控制器和方法判断是否有对应权限
                if (!$this->auth->check($path))
                {
                    $this->error(__('You have no permission'));
                }
            }
        }
        else
        {
            // 如果有传递token才验证是否登录状态
            if ($token)
            {
                $this->auth->init($token);
            }
        }

        // 如果有使用模板布局
        if ($this->layout)
        {
            $this->view->engine->layout('layout/' . $this->layout);
        }

        $this->view->assign('user', $this->auth->getUser());

        $site = Config::get("site");

        $upload = \app\common\model\Config::upload();

        // 上传信息配置后
        Hook::listen("upload_config_init", $upload);
        Config::set('upload', array_merge(Config::get('upload'), $upload));

        // 加载当前控制器语言包
        $this->assign('site', $site);
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
            $this->data[$name] = $value;
        }

        return $this;
    }
    
    /**
     * 加载模板输出
     * @access protected
     * @param string $template 模板文件名或者内容
     * @param array  $vars     模板变量
     * @return mixed
     */
    protected function fetch($template = '', $vars = [])
    {
        $controller = parseName($this->controller);
        if ('think' == strtolower(Config::get('view.type')) && $controller && 0 !== strpos($template, '/'))
        {
            $depr = Config::get('view.view_depr');
            $template = str_replace(['/', ':'], $depr, $template);
            if ('' == $template)
            {
                // 如果模板文件名为空 按照默认规则定位
                $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $this->action;
            }
            elseif (false === strpos($template, $depr))
            {
                $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $template;
            }
        }

        View::engine()->config(['view_path' => ADDON_PATH . $this->addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR]);
        return View::fetch($template, $vars);

    }

}
