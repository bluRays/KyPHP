<?php
// +----------------------------------------------------------------------
// | [KyPHP System] Copyright (c) 2020 http://www.kuryun.com/
// +----------------------------------------------------------------------
// | [KyPHP] 并不是自由软件,你可免费使用,未经许可不能去掉KyPHP相关版权
// +----------------------------------------------------------------------
// | Author: fudaoji <fdj@kuryun.cn>
// +----------------------------------------------------------------------

/**
 * Created by PhpStorm.
 * Script Name: App.php
 * Create: 2020/5/17 下午5:08
 * Description: 应用中心
 * Author: fudaoji<fdj@kuryun.cn>
 */

namespace app\admin\controller;

use think\Validate;

class App extends Base
{
    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
    }

    /**
     * 卸载应用
     * Author: fudaoji<fdj@kuryun.cn>
     */
    public function uninstall()
    {
        if (request()->isPost()) {
            $name = input('name', '');
            if ($name == '') {
                $this->error('没有要卸载的应用');
            }
            $path= ADDON_PATH . $name . DS;
            if(!file_exists($path)){
                $this->error($path.'目录不存在');
            }
            if(!is_writable($path)){
                $this->error($path.'目录没有删除权限');
            }

            $res = controller('common/addon', 'event')->clearAddonData(['name' => $name]);
            if($res === true){
                if(del_dir_recursively($path)){
                    $this->success('应用卸载成功，数据及文件都已删除');
                }else{
                    $this->error('删除应用目录失败');
                }
            }else{
                $this->error('还原失败，失败原因请查看日志');
            }
        }
    }

    /**
     * 数据还原
     * Author: fudaoji<fdj@kuryun.cn>
     */
    public function wipeData()
    {
        if (request()->isPost()) {
            $name = input('name', '');
            if ($name == '') {
                $this->error('没有要还原的应用');
            }
            $res = controller('common/addon', 'event')->clearAddonData(['name' => $name]);
            if($res === true){
                $this->success('应用还原成功');
            }else{
                $this->error('还原失败，失败原因请查看日志');
            }
        }
    }

    /**
     * 安装/启用应用扩展
     * @author: fudaoji<fdj@kuryun.cn>
     */
    public function install()
    {
        if (request()->isPost()) {
            $name = input('name', '');
            if ($name == '') {
                $this->error('没有要安装的应用');
            }
            cache('callAddonCache'.$name, null);
            if ($addon = model('addons')->getOneByMap(['addon' => $name])) {
                if ($addon['status'] == 1) {
                    $this->error('应用已安装，请先卸载再重新安装');
                } else {
                    model('addons')->updateOne(['id' => $addon['id'], 'status' => 1]);
                    $this->success('安装应用成功');
                }
            } else {
                $cf = model('addons')->getAddonConfigByFile($name);
                $data = [
                    'name' => isset($cf['name']) ? $cf['name'] : '',
                    'addon' => isset($cf['addon']) ? $cf['addon'] : '',
                    'desc' => isset($cf['desc']) ? $cf['desc'] : '',
                    'version' => isset($cf['version']) ? $cf['version'] : '',
                    'author' => isset($cf['author']) ? $cf['author'] : '',
                    'logo' => isset($cf['logo']) ? $cf['logo'] : '',
                    'menu_show' => isset($cf['menu_show']) ? $cf['menu_show'] : '1',
                    'entry_url' => isset($cf['entry_url']) ? $cf['entry_url'] : '',
                    'admin_url' => isset($cf['admin_url']) ? $cf['admin_url'] : '',
                    'config' => isset($cf['config']) ? json_encode($cf['config']) : '',
                    'status' => 1
                ];
                $validate = new Validate(
                    [
                        'name' => 'require',
                        'addon' => 'require',
                        'version' => 'require',
                        'logo' => 'require',
                        'author' => 'require',
                    ],
                    [
                        'title.require' => '应用名称不能为空',
                        'addon.require' => '应用标识不能为空',
                        'version.require' => '版本不能为空',
                        'logo.require' => 'Logo不能为空',
                        'author.require' => '作者信息不能为空',
                    ]
                );
                $result = $validate->check($data);
                if ($result === false) {
                    $this->error($validate->getError());
                }

                if (isset($cf['install_sql']) && $cf['install_sql'] != '') {
                    $instal_file = ADDON_PATH . $name . DS . $cf['install_sql'];
                    if (!is_file($instal_file)) {
                        $this->error('没有找到安装数据的SQL文件：' . $cf['install_sql']);
                    } else {
                        if (!strpos($instal_file, '.sql')) {
                            $this->error('安装文件格式有误');
                        }
                        execute_addon_install_sql($instal_file);
                    }
                }
                if (model('addons')->addOne($data)) {
                    $this->success('安装应用成功');
                }else{
                    $this->error('安装应用失败');
                }
            }
        }
    }

    /**
     * 卸载|关闭应用扩展
     *
     */
    public function close()
    {
        if (request()->isPost()) {
            $name = input('name', '');
            $addon = model('addons')->getOneByMap(['addon' => $name, 'status' => 1]);
            if (empty($addon)) {
                $this->error('没有要停用的应用');
            }
            cache('callAddonCache'.$name, null);

            if (model('addons')->updateOne(['status' => 0, 'id' => $addon['id']])) {
                $this->success('停用应用成功');
            }
            $this->error('停用应用失败');
        }
    }


    /**
     * 应用中心
     * @param string $type
     * @return mixed
     * @author: fudaoji<fdj@kuryun.cn>
     */
    public function index($type = 'uninstall')
    {
        if (!cookie('mpInfo')) {
            //$this->error('请先进入公众号，再操作', url('mp/index/mplist'));
        }
        $apps = model('addons')->getAll(['where' => ['status' => 1]]);
        $addon_list = $apps;

        if ($type == 'notinstall') { //未安装
            $addon_folder = opendir(ADDON_PATH);
            $addons = []; //存放未安装的插件
            if ($addon_folder) {
                while (($file = readdir($addon_folder)) !== false) {
                    if ($file != '.' && $file != '..') {
                        if ($addon_local = model('addons')->getAddonConfigByFile($file)) {
                            $addon_db = model('addons')->getOneByMap(['addon' => $file]);
                            if (empty($addon_db) || $addon_db['status'] == 0) {
                                $addons[] = $addon_local;
                            }
                        }
                    }
                }
            }
            $addon_list = $addons;
        }

        foreach ($addon_list as $key => $value) {
            if ($type == 'index') {
                $upgrade_sql_file = ADDON_PATH . $value['addon'] . '/upgrade.sql';
                $addon_list[$key]['upgrade'] = is_file($upgrade_sql_file) ? 1 : 0;
            }
            if($type == 'notinstall'){
                $addon_list[$key]['logo'] = controller('common/addon', 'event')->getAddonLogoLocal(['name' => $value['addon']]);
            }
        }

        $assign = [
            'addons' => $addon_list,
            'apps' => $apps,
            'type' => $type,
            'menu_title' => '公众号应用管理'
        ];
        return $this->show($assign);
    }
}