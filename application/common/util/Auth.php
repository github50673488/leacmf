<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2011 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: luofei614 <weibo.com/luofei614>
// +----------------------------------------------------------------------
// | 修改者: anuo (本权限类在原3.2.3的基础上修改过来的)
// +----------------------------------------------------------------------

namespace app\common\util;

use lea\Tree;
use think\Db;
use think\Config;
use think\Session;
use think\Request;
use think\Loader;

/**
 * 权限认证类
 * 功能特性：
 * 1，是对规则进行认证，不是对节点进行认证。用户可以把节点当作规则名称实现对节点进行认证。
 *      $auth=new Auth();  $auth->check('规则名称','用户id')
 * 2，可以同时对多条规则进行认证，并设置多条规则的关系（or或者and）
 *      $auth=new Auth();  $auth->check('规则1,规则2','用户id','and')
 *      第三个参数为and时表示，用户需要同时具有规则1和规则2的权限。 当第三个参数为or时，表示用户值需要具备其中一个条件即可。默认为or
 * 3，一个用户可以属于多个用户组(think_auth_group_access表 定义了用户所属用户组)。我们需要设置每个用户组拥有哪些规则(think_auth_group 定义了用户组权限)
 * 4，支持规则表达式。
 *      在think_auth_rule 表中定义一条规则，condition字段就可以定义规则表达式。 如定义{score}>5  and {score}<100
 * 表示用户的分数在5-100之间时这条规则才会通过。
 */
class Auth
{

    /**
     * @var object 对象实例
     */
    protected static $instance;

    /**
     * 当前请求实例
     * @var Request
     */
    protected $request;
    //默认配置
    protected $config = [
        'auth_on'           => 1, // 权限开关
        'auth_type'         => 1, // 认证方式，1为实时认证；2为登录认证。
        'auth_group'        => 'auth_group', // 用户组数据表名
        'auth_group_access' => 'auth_group_access', // 用户-用户组关系表
        'auth_rule'         => 'auth_rule', // 权限规则表
        'auth_user'         => 'user', // 用户信息表
    ];

    /**
     * 类架构函数
     * Auth constructor.
     */
    public function __construct()
    {
        if ($auth = Config::get('auth')) {
            $this->config = array_merge($this->config, $auth);
        }
        // 初始化request
        $this->request = Request::instance();
    }

    /**
     * 初始化
     * @access public
     * @param array $options 参数
     * @return Auth
     */
    public static function instance($options = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new static($options);
        }

        return self::$instance;
    }

    /**
     * 检查权限
     * @param              $name     string|array    需要验证的规则列表,支持逗号分隔的权限规则或索引数组
     * @param              $uid      int             认证用户的id
     * @param       string $relation 如果为 'or' 表示满足任一条规则即通过验证;如果为 'and'则表示需满足所有规则才能通过验证
     * @param       string $mode     执行验证的模式,可分为url,normal
     * @return bool               通过验证返回true;失败返回false
     */
    public function check($name, $uid, $relation = 'or', $mode = 'url')
    {
        if (!$this->config['auth_on']) {
            return true;
        }
        // 获取用户需要验证的所有有效规则列表
        $rulelist = $this->getRuleList($uid);
        if (in_array('*', $rulelist))
            return true;

        if (is_string($name)) {
            $name = strtolower($name);
            if (strpos($name, ',') !== false) {
                $name = explode(',', $name);
            } else {
                $name = [$name];
            }
        }
        $list = []; //保存验证通过的规则名
        if ('url' == $mode) {
            $REQUEST = unserialize(strtolower(serialize($this->request->param())));
        }
        foreach ($rulelist as $rule) {
            $query = preg_replace('/^.+\?/U', '', $rule);
            if ('url' == $mode && $query != $rule) {
                parse_str($query, $param); //解析规则中的param
                $intersect = array_intersect_assoc($REQUEST, $param);
                $rule      = preg_replace('/\?.*$/U', '', $rule);
                if (in_array($rule, $name) && $intersect == $param) {
                    //如果节点相符且url参数满足
                    $list[] = $rule;
                }
            } else {
                if (in_array($rule, $name)) {
                    $list[] = $rule;
                }
            }
        }
        if ('or' == $relation && !empty($list)) {
            return true;
        }
        $diff = array_diff($name, $list);
        if ('and' == $relation && empty($diff)) {
            return true;
        }

        return false;
    }

    /**
     * 根据用户id获取用户组,返回值为数组
     * @param  $uid int     用户id
     * @return array       用户所属的用户组 array(
     *              array('uid'=>'用户id','group_id'=>'用户组id','name'=>'用户组名称','rules'=>'用户组拥有的规则id,多个,号隔开'),
     *              ...)
     */
    public function getGroups($uid)
    {
        static $groups = [];
        if (isset($groups[$uid])) {
            return $groups[$uid];
        }
        // 转换表名
        $auth_group_access = $this->config['auth_group_access'];
        $auth_group        = $this->config['auth_group'];
        // 执行查询
        $user_groups  = Db::view($auth_group_access, 'uid,group_id')
            ->view($auth_group, 'id,name,rules', "{$auth_group_access}.group_id={$auth_group}.id", 'LEFT')
            ->where("{$auth_group_access}.uid='{$uid}' and {$auth_group}.status='1'")
            ->select();
        $groups[$uid] = $user_groups ?: [];

        return $groups[$uid];
    }

    /**
     * 获得权限规则列表
     * @param integer $uid 用户id
     * @return array
     */
    public function getRuleList($uid)
    {
        static $_rulelist = []; //保存用户验证通过的权限列表
        if (isset($_rulelist[$uid])) {
            return $_rulelist[$uid];
        }
        if (2 == $this->config['auth_type'] && Session::has('_rule_list_' . $uid)) {
            return Session::get('_rule_list_' . $uid);
        }

        // 读取用户规则节点
        $ids = $this->getRuleIds($uid);
        if (empty($ids)) {
            $_rulelist[$uid] = [];
            return [];
        }

        // 筛选条件
        $where = [
            'status' => '1'
        ];

        //循环规则，判断结果。
        $rulelist = []; //
        if (!in_array('*', $ids)) {
            $where['id'] = ['in', $ids];
        }
        //读取用户组所有权限规则
        $rules = Db::name($this->config['auth_rule'])->where($where)->field('id,condition,name,is_menu')->select();

        foreach ($rules as $rule) {
            //超级管理员无需验证condition
            if (!empty($rule['condition']) && !in_array('*', $ids)) {
                //根据condition进行验证
                $user    = $this->getUserInfo($uid); //获取用户信息,一维数组
                $command = preg_replace('/\{(\w*?)\}/', '$user[\'\\1\']', $rule['condition']);
                @(eval('$condition=(' . $command . ');'));
                if ($condition) {
                    $rulelist[$rule['id']] = strtolower($rule['name']);
                }
            } else {
                //只要存在就记录
                $rulelist[$rule['id']] = strtolower($rule['name']);
            }
        }
        $_rulelist[$uid] = $rulelist;

        //登录验证则需要保存规则列表
        if (2 == $this->config['auth_type']) {
            //规则列表结果保存到session
            Session::set('_rule_list_' . $uid, $rulelist);
        }
        return array_unique($rulelist);
    }

    public function getRuleIds($uid)
    {
        //读取用户所属用户组
        $groups = $this->getGroups($uid);
        $ids    = []; //保存用户所属用户组设置的所有权限规则id
        foreach ($groups as $g) {
            $ids = array_merge($ids, explode(',', trim($g['rules'], ',')));
        }
        $ids = array_unique($ids);
        return $ids;
    }

    /**
     * 获得用户资料
     * @param $uid
     * @return mixed
     */
    protected function getUserInfo($uid)
    {
        static $user_info = [];

        $user = Db::name($this->config['auth_user']);
        // 获取用户表主键
        $_pk = is_string($user->getPk()) ? $user->getPk() : 'uid';
        if (!isset($user_info[$uid])) {
            $user_info[$uid] = $user->where($_pk, $uid)->find();
        }

        return $user_info[$uid];
    }

    /**
     * 获取用户菜单
     */
    public function getMenu($uid, $path)
    {
        $self = Db::name($this->config['auth_rule'])->field('id,pid,name,title,icon,remark')->where('name', $path)->find();

        $map      = ['status' => 1, 'is_menu' => 1];
        $rule_ids = $this->getRuleIds($uid);
        if ($rule_ids && $rule_ids[0] != '*') {
            $map['id'] = ['in', $rule_ids];
        }

        $list = cache('sys:cache:menu:' . $uid);
        if (!$list) {
            $list = Db::name($this->config['auth_rule'])->field('id,pid,name,title,icon,sort')->where($map)->order('pid asc,sort asc,id asc')->select();
            cache('sys:cache:menu:' . $uid, $list);
        }

        $crumb      = Tree::getParents($list, $self['id']);
        $parent_ids = [];

        foreach ($crumb as $val) {
            array_push($parent_ids, $val['id']);
        }

        //dump($list);exit();

        $menu         = list_to_tree($list,'id','pid','child');
        $sort_by_sort = function ($x, $y) {
            if ($x['sort'] == $y['sort']) {
                return 0;
            }
            return ($x['sort'] < $y['sort']) ? -1 : 1;
        };
        uasort($menu, $sort_by_sort);
        $data = [
            'menu'  => $menu,
            'crumb' => $crumb,
            'pids'  => $parent_ids,
            'self'  => $self
        ];
        return $data;
    }

    //获取菜单面包屑
    public function getCrumbs($path)
    {
        $self = Db::name($this->config['auth_rule'])->field('id,pid,name,title,icon')->where('name', $path)->find();
        //获取所有菜单
        $list = cache('sys:cache:menu');
        if (empty($list)) {
            $list = Db::name($this->config['auth_rule'])->field('id,pid,name,title,icon,is_menu')->where('status', 1)->select();
            cache('sys:cache:menu', $list);
        }
        $crumb = Tree::getParents($list, $self['id']);
        return $crumb;
    }

}