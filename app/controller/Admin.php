<?php
namespace app\controller;

use app\BaseController;
use app\lib\WechatServer;
use think\facade\Db;
use think\facade\View;
use think\facade\Request;
use think\facade\Cache;

class Admin extends BaseController
{
    public function verifycode()
    {
        return captcha();
    }

    public function login()
    {
        if (request()->islogin) {
            return redirect('/admin');
        }
        if (request()->isAjax()) {
            $username = input('post.username', null, 'trim');
            $password = input('post.password', null, 'trim');
            $code = input('post.captcha', null, 'trim');

            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
            }
            if (!captcha_check($code)) {
                return json(['code' => -1, 'msg' => '验证码错误']);
            }
            if ($username === config_get('admin_username') && $password === config_get('admin_password')) {
                Db::name('log')->insert(['uid' => 0, 'action' => '登录后台', 'data' => 'IP:'.$this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
                $session = md5($username.config_get('admin_password'));
                $expiretime = time() + 2562000;
                $token = authcode("{$username}\t{$session}\t{$expiretime}", 'ENCODE', config_get('syskey'));
                cookie('admin_token', $token, ['expire' => $expiretime, 'httponly' => true]);
                config_set('admin_lastlogin', date('Y-m-d H:i:s'));
                return json(['code' => 0]);
            } else {
                return json(['code' => -1, 'msg' => '用户名或密码错误']);
            }
        }
        View::assign('qqlogin', $this->qqReady());
        return view();
    }


    /** QQ 互联是否已启用并配置好（不要求已绑定） */
    private function qqReady()
    {
        return config_get('qqlogin_enable') && config_get('qqlogin_appid')
            && config_get('qqlogin_appkey');
    }

    private function qqCallbackUrl()
    {
        return rtrim(request()->domain(), '/') . '/admin/qqcallback';
    }

    private function qqState($intent)
    {
        $state = md5(random(16) . microtime(true));
        session('qq_state', $state);
        session('qq_intent', $intent);
        return $state;
    }

    private function qqAuthorizeUrl($state)
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => config_get('qqlogin_appid'),
            'redirect_uri'  => $this->qqCallbackUrl(),
            'state'         => $state,
            'scope'         => 'get_user_info',
        ]);
        return 'https://graph.qq.com/oauth2.0/authorize?' . $query;
    }

    private function qqRequest($url)
    {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'wxredirect',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            \think\facade\Log::error('qq oauth: ' . $error);
            return '';
        }
        return $body;
    }

    private function qqAccessToken($code)
    {
        $url = 'https://graph.qq.com/oauth2.0/token?' . http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => config_get('qqlogin_appid'),
            'client_secret' => config_get('qqlogin_appkey'),
            'code'          => $code,
            'redirect_uri'  => $this->qqCallbackUrl(),
            'fmt'           => 'json',
        ]);
        $data = json_decode($this->qqRequest($url), true);
        return is_array($data) && !empty($data['access_token']) ? $data['access_token'] : '';
    }

    private function qqOpenid($token)
    {
        $url = 'https://graph.qq.com/oauth2.0/me?' . http_build_query([
            'access_token' => $token,
            'fmt'          => 'json',
        ]);
        $data = json_decode($this->qqRequest($url), true);
        return is_array($data) && !empty($data['openid']) ? $data['openid'] : '';
    }

    private function qqUserInfo($token, $openid)
    {
        $url = 'https://graph.qq.com/user/get_user_info?' . http_build_query([
            'access_token'       => $token,
            'oauth_consumer_key' => config_get('qqlogin_appid'),
            'openid'             => $openid,
        ]);
        $data = json_decode($this->qqRequest($url), true);
        return is_array($data) && isset($data['ret']) && $data['ret'] == 0 ? $data : [];
    }

    /**
     * 登录时判断身份：已绑定则比对 openid；未绑定且允许自动绑定时，把当前 QQ 绑为管理员
     */
    private function qqMatchOrBind($openid, $nickname)
    {
        $bound = (string)config_get('qqlogin_openid');
        if ($bound !== '') {
            return $bound === $openid;
        }
        // config_get 会把 '0' 当成空值，这里直接读原始配置：未设置视为开启
        $autobind = config('sys.qqlogin_autobind');
        if ($autobind !== null && (string)$autobind === '0') {
            return false;
        }
        config_set('qqlogin_openid', $openid);
        config_set('qqlogin_nickname', $nickname);
        cache('configs', null);
        return true;
    }

    private function qqLoginSession()
    {
        $username = config_get('admin_username');
        $session = md5($username . config_get('admin_password'));
        $expiretime = time() + 2562000;
        $token = authcode("{$username}\t{$session}\t{$expiretime}", 'ENCODE', config_get('syskey'));
        cookie('admin_token', $token, ['expire' => $expiretime, 'httponly' => true]);
        config_set('admin_lastlogin', date('Y-m-d H:i:s'));
        Db::name('log')->insert([
            'uid'     => 0,
            'action'  => '登录后台',
            'data'    => 'IP:' . $this->clientip . '（QQ 一键登录）',
            'addtime' => date("Y-m-d H:i:s"),
        ]);
    }

    private function qqMessage($msg)
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>QQ 一键登录</title>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',sans-serif">'
            . '<div style="max-width:420px;margin:15vh auto;background:#fff;border-radius:8px;padding:28px 24px;box-shadow:0 2px 12px rgba(0,0,0,.08)">'
            . '<h3 style="margin:0 0 12px;font-size:18px;color:#333">QQ 一键登录</h3>'
            . '<p style="margin:0 0 20px;color:#666;line-height:1.7">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a href="/admin/login" style="display:inline-block;padding:9px 18px;border-radius:4px;background:#1E9FFF;color:#fff;text-decoration:none">返回登录</a>'
            . '</div></body></html>';
        return response($html);
    }

    /** 发起 QQ 登录（登录页按钮） */
    public function qqlogin()
    {
        if (!config_get('qqlogin_enable') || !config_get('qqlogin_appid') || !config_get('qqlogin_appkey')) {
            return $this->qqMessage('后台没有开启 QQ 一键登录。');
        }
        return redirect($this->qqAuthorizeUrl($this->qqState('login')));
    }

    /** 已登录状态下绑定当前 QQ */
    public function qqbind()
    {
        if (!request()->islogin) {
            return redirect('/admin/login');
        }
        if (!config_get('qqlogin_appid') || !config_get('qqlogin_appkey')) {
            return redirect('/admin/qqset');
        }
        return redirect($this->qqAuthorizeUrl($this->qqState('bind')));
    }

    /** QQ 互联回调 */
    public function qqcallback()
    {
        $code = trim((string)input('get.code', ''));
        $state = trim((string)input('get.state', ''));
        $saved = session('qq_state');
        $intent = session('qq_intent');
        session('qq_state', null);
        session('qq_intent', null);

        if (!$code || !$state || !$saved || $state !== $saved) {
            return $this->qqMessage('授权校验失败，请重新发起。');
        }
        if ($intent == 'bind' && !request()->islogin) {
            return $this->qqMessage('登录状态已失效，请重新登录后台再绑定。');
        }

        $token = $this->qqAccessToken($code);
        if (!$token) {
            return $this->qqMessage('获取 access_token 失败，请检查 AppID / AppKey 与回调地址。');
        }
        $openid = $this->qqOpenid($token);
        if (!$openid) {
            return $this->qqMessage('获取 openid 失败，请稍后再试。');
        }

        if ($intent == 'bind') {
            $info = $this->qqUserInfo($token, $openid);
            config_set('qqlogin_openid', $openid);
            config_set('qqlogin_nickname', $info['nickname'] ?? '');
            cache('configs', null);
            return redirect('/admin/qqset');
        }

        $info = $this->qqUserInfo($token, $openid);
        if (!$this->qqMatchOrBind($openid, $info['nickname'] ?? '')) {
            return $this->qqMessage('这个 QQ 不是已绑定的管理员 QQ，无法登录。');
        }

        $this->qqLoginSession();
        return redirect('/admin/index');
    }

    /** QQ 一键登录设置 */
    public function qqset()
    {
        if (request()->isAjax()) {
            config_set('qqlogin_enable', input('post.enable') ? 1 : 0);
            config_set('qqlogin_autobind', input('post.autobind') ? 1 : 0);
            config_set('qqlogin_appid', trim((string)input('post.appid', '')));
            config_set('qqlogin_appkey', trim((string)input('post.appkey', '')));
            cache('configs', null);
            return json(['code' => 0]);
        }
        View::assign('callback', $this->qqCallbackUrl());
        return view();
    }

    /** 解除绑定 */
    public function qqunbind()
    {
        if (!request()->isAjax()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        config_set('qqlogin_openid', '');
        config_set('qqlogin_nickname', '');
        cache('configs', null);
        return json(['code' => 0]);
    }

    public function logout()
    {
        cookie('admin_token', null);
        return redirect('/admin/login');
    }

    public function index()
    {
        if (request()->isAjax()) {
            if (input('post.do') == 'stat') {
                $stat = ['domains' => 0, 'logins' => 0, 'logins_today' => 0, 'tokens' => 0];
                $stat['domains'] = Db::name('domain')->where('status', 1)->count();
                $stat['logins'] = Db::name('record')->where('status', 1)->count();
                $stat['logins_today'] = Db::name('record')->whereTime('addtime', '>=', date("Y-m-d"))->where('status', 1)->count();
                $stat['tokens'] = Db::name('token')->where('status', 1)->count();
                return json($stat);
            } elseif (input('post.do') == 'cleanlogin') {
                Db::name('record')->whereTime('addtime', '<', date("Y-m-d H:i:s", strtotime("-24 hours")))->delete();
                Db::execute("OPTIMIZE TABLE `".config('database.connections.mysql.prefix')."record`");
                return json(['code' => 0]);
            }
            return json(['code' => -3]);
        }

        if (config('app.dbversion') && config_get('version') != config('app.dbversion')) {
            $this->db_update();
            config_set('version', config('app.dbversion'));
            Cache::clear();
        }

        $tmp = 'version()';
        $mysqlVersion = Db::query("select version()")[0][$tmp];
        $info = [
            'framework_version' => app()::VERSION,
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'software' => $_SERVER['SERVER_SOFTWARE'],
            'os' => php_uname(),
            'date' => date("Y-m-d H:i:s"),
        ];
        View::assign('info', $info);
        View::assign('checkupdate', '//auth.cccyun.cc/app/wechat.php?ver='.config('app.version'));
        return view();
    }

    private function db_update()
    {
        $sqls = file_get_contents(app()->getAppPath().'sql/update.sql');
        $mysql_prefix = env('database.prefix', 'wechat_');
        $sqls = explode(';', $sqls);
        foreach ($sqls as $value) {
            $value = trim($value);
            if (empty($value)) continue;
            $value = str_replace('wechat_', $mysql_prefix, $value);
            Db::execute($value);
        }
    }

    public function domain()
    {
        if (request()->isAjax()) {
            $act = input('post.act');
            if ($act == 'add') {
                $domain = input('post.domain', null, 'trim');
                if (empty($domain)) return json(['code' => -1, 'msg' => '域名不能为空']);
                if (!checkDomain($domain)) return json(['code' => -1, 'msg' => '域名格式不正确']);
                if (Db::name('domain')->where('domain', $domain)->find()) {
                    return json(['code' => -1, 'msg' => '该域名已存在，请勿重复添加']);
                }
                Db::name('domain')->insert([
                    'domain' => $domain,
                    'status' => 1,
                    'addtime' => date("Y-m-d H:i:s")
                ]);
                return json(['code' => 0, 'msg' => '添加域名成功！']);
            } elseif ($act == 'set') {
                $id = input('post.id/d');
                $status = input('post.status/d');
                Db::name('domain')->where('id', $id)->update(['status' => $status]);
                return json(['code' => 0]);
            } elseif ($act == 'del') {
                $id = input('post.id/d');
                Db::name('domain')->where('id', $id)->delete();
                return json(['code' => 0]);
            }
            return json(['code' => -3]);
        }
        return view();
    }

    public function domain_data()
    {
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('domain');
        if (!empty($kw)) {
            $select->whereLike('domain', '%'.$kw.'%');
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function record()
    {
        return view();
    }

    public function record_data()
    {
        $domain = input('post.domain', null, 'trim');
        $did = input('post.did/d');
        $appid = input('post.appid', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('record')->alias('A')->join('domain B', 'A.did = B.id')->where('A.status', 1);
        if (!empty($domain)) {
            $did = Db::name('domain')->where('domain', $domain)->value('id');
            $select->where('did', $did);
        } elseif (!empty($did)) {
            $select->where('did', $did);
        }
        if (!empty($appid)) {
            $select->where('appid', $appid);
        }
        $total = $select->count();
        $rows = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.domain')->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function wxtoken()
    {
        if (request()->isAjax()) {
            $act = input('get.act');
            if ($act == 'get') {
                $id = input('get.id/d');
                $row = Db::name('token')->where('id', $id)->find();
                return json(['code' => 0, 'data' => $row]);
            } elseif ($act == 'set') {
                $id = input('get.id/d');
                $status = input('get.status/d');
                Db::name('token')->where('id', $id)->update(['status' => $status]);
                return json(['code' => 0]);
            } elseif ($act == 'del') {
                $id = input('get.id/d');
                Db::name('token')->where('id', $id)->delete();
                return json(['code' => 0]);
            } elseif ($act == 'save') {
                $id = input('post.id/d');
                $type = input('post.type/d');
                $name = input('post.name', null, 'trim');
                $appid = input('post.appid', null, 'trim');
                $appsecret = input('post.appsecret', null, 'trim');
                if (!$name || !$appid || !$appsecret) return json(['code' => -1, 'msg' => '必填项不能为空']);
                if (input('post.action') == 'add') {
                    if (Db::name('token')->where('name', $name)->find()) {
                        return json(['code' => -1, 'msg' => '名称重复']);
                    }
                    Db::name('token')->insert([
                        'type' => $type,
                        'name' => $name,
                        'status' => 1,
                        'appid' => $appid,
                        'appsecret' => $appsecret,
                        'addtime' => date("Y-m-d H:i:s")
                    ]);
                    return json(['code' => 0, 'msg' => '添加微信Token成功！']);
                } else {
                    if (Db::name('token')->where('name', $name)->where('id', '<>', $id)->find()) {
                        return json(['code' => -1, 'msg' => '名称重复']);
                    }
                    Db::name('token')->where('id', $id)->update([
                        'type' => $type,
                        'name' => $name,
                        'appid' => $appid,
                        'appsecret' => $appsecret,
                    ]);
                    return json(['code' => 0, 'msg' => '修改微信Token成功！']);
                }
            }
            return json(['code' => -3]);
        }
        return view();
    }

    public function wxtoken_data()
    {
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('token');
        if (!empty($kw)) {
            $select->whereLike('name|appid', '%'.$kw.'%');
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function textwxtoken()
    {
        $id = input('post.id/d');
        $row = Db::name('token')->where('id', $id)->find();
        if (!$row) return json(['code' => -1, 'msg' => '记录不存在']);
        try {
            if ($row['type'] == 3) {
                refresh_qywx_access_token($row['id'], true);
            } else {
                refresh_wx_access_token($row['id'], true);
            }
            return json(['code' => 0, 'msg' => '接口连接测试成功！']);
        } catch (\Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function servergroup()
    {
        if (request()->isAjax()) {
            $act = input('get.act');
            if ($act == 'get') {
                $id = input('get.id/d');
                $row = Db::name('servergroup')->where('id', $id)->find();
                $row['url'] = request()->root(true).'/wxserver/id/'.$id;
                return json(['code' => 0, 'data' => $row]);
            } elseif ($act == 'del') {
                $id = input('get.id/d');
                Db::name('servergroup')->where('id', $id)->delete();
                Db::name('serveritem')->where('gid', $id)->delete();
                return json(['code' => 0]);
            } elseif ($act == 'save') {
                $id = input('post.id/d');
                $type = input('post.type/d');
                $mode = input('post.mode/d');
                $name = input('post.name', null, 'trim');
                $server_token = input('post.server_token', null, 'trim');
                $server_enckey = input('post.server_enckey', null, 'trim');
                if (!$name) return json(['code' => -1, 'msg' => '名称不能为空']);
                if (input('post.action') == 'add') {
                    if (Db::name('servergroup')->where('name', $name)->find()) {
                        return json(['code' => -1, 'msg' => '名称重复']);
                    }
                    Db::name('servergroup')->insert([
                        'type' => $type,
                        'mode' => $mode,
                        'name' => $name,
                        'token' => md5(uniqid(mt_rand(), true).microtime()),
                        'addtime' => date("Y-m-d H:i:s")
                    ]);
                    return json(['code' => 0, 'msg' => '添加微信转发服务器组成功！其他信息请点击修改信息查看']);
                } else {
                    if (!$server_token) return json(['code' => -1, 'msg' => 'Token不能为空']);
                    if (Db::name('servergroup')->where('name', $name)->where('id', '<>', $id)->find()) {
                        return json(['code' => -1, 'msg' => '名称重复']);
                    }
                    Db::name('servergroup')->where('id', $id)->update([
                        'type' => $type,
                        'mode' => $mode,
                        'name' => $name,
                        'token' => $server_token,
                        'enckey' => $type == 1 ? $server_enckey : null,
                    ]);
                    return json(['code' => 0, 'msg' => '修改微信转发服务器组成功！']);
                }
            }
            return json(['code' => -3]);
        }
        return view();
    }

    public function servergroup_data()
    {
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('servergroup');
        if (!empty($kw)) {
            $select->whereLike('name', '%'.$kw.'%');
        }
        $total = $select->count();
        $rows = $select->alias('A')->fieldRaw("A.*,(SELECT COUNT(*) FROM ".env('database.prefix', 'wechat_')."serveritem WHERE gid=A.id) itemnum")->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function serveritem()
    {
        if (request()->isAjax()) {
            $act = input('get.act');
            if ($act == 'get') {
                $id = input('get.id/d');
                $row = Db::name('serveritem')->where('id', $id)->find();
                return json(['code' => 0, 'data' => $row]);
            } elseif ($act == 'del') {
                $id = input('get.id/d');
                Db::name('serveritem')->where('id', $id)->delete();
                return json(['code' => 0]);
            } elseif ($act == 'set') {
                $id = input('post.id/d');
                $status = input('post.status/d');
                Db::name('serveritem')->where('id', $id)->update(['status' => $status]);
                return json(['code' => 0]);
            } elseif ($act == 'save') {
                $id = input('post.id/d');
                $gid = input('post.gid/d');
                $sort = input('post.sort/d');
                $server_url = input('post.server_url', null, 'trim');
                if (!$server_url) return json(['code' => -1, 'msg' => '服务器URL不能为空']);
                $parsed = parse_url($server_url);
                if (empty($parsed['scheme']) || empty($parsed['host']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
                    return json(['code' => -1, 'msg' => '服务器URL格式不正确']);
                }
                if ($parsed['host'] == request()->host()) return json(['code' => -1, 'msg' => '服务器URL不能是当前站点']);
                if (input('post.action') == 'add') {
                    if (Db::name('serveritem')->where('gid', $gid)->where('url', $server_url)->find()) {
                        return json(['code' => -1, 'msg' => '服务器URL已存在']);
                    }
                    $group = Db::name('servergroup')->where('id', $gid)->find();
                    if (!$group) return json(['code' => -1, 'msg' => '服务器组不存在']);
                    $server = new WechatServer($group['token'], $group['enckey']);
                    if (($group['type'] == 0 && !$server->verifyWechatServer($server_url)) || ($group['type'] == 1 && !$server->verifyWeWorkServer($server_url))) {
                        return json(['code' => -1, 'msg' => '服务器Token验证失败']);
                    }
                    Db::name('serveritem')->insert([
                        'gid' => $gid,
                        'sort' => $sort,
                        'url' => $server_url,
                        'status' => 1,
                        'addtime' => date("Y-m-d H:i:s")
                    ]);
                    return json(['code' => 0, 'msg' => '添加转发服务器成功！']);
                } else {
                    if (Db::name('serveritem')->where('gid', $gid)->where('url', $server_url)->where('id', '<>', $id)->find()) {
                        return json(['code' => -1, 'msg' => '服务器URL已存在']);
                    }
                    Db::name('serveritem')->where('id', $id)->update([
                        'sort' => $sort,
                        'url' => $server_url,
                    ]);
                    return json(['code' => 0, 'msg' => '修改转发服务器成功！']);
                }
            }
            return json(['code' => -3]);
        }
        $gid = input('get.gid/d');
        $rows = Db::name('serveritem')->where('gid', $gid)->order('sort', 'asc')->select();
        View::assign('gid', $gid);
        View::assign('rows', $rows);
        return view();
    }

    public function set()
    {
        if (request()->isAjax()) {
            $params = Request::param();
            if (isset($params['username'])) $params['username'] = trim($params['username']);
            if (isset($params['oldpwd'])) $params['oldpwd'] = trim($params['oldpwd']);
            if (isset($params['newpwd'])) $params['newpwd'] = trim($params['newpwd']);
            if (isset($params['newpwd2'])) $params['newpwd2'] = trim($params['newpwd2']);

            if (empty($params['username'])) return json(['code' => -1, 'msg' => '用户名不能为空']);

            if (!empty($params['oldpwd']) && !empty($params['newpwd']) && !empty($params['newpwd2'])) {
                if (config_get('admin_password') != $params['oldpwd']) {
                    return json(['code' => -1, 'msg' => '旧密码不正确']);
                }
                if ($params['newpwd'] != $params['newpwd2']) {
                    return json(['code' => -1, 'msg' => '两次新密码输入不一致']);
                }
                config_set('admin_password', $params['newpwd']);
            }

            config_set('admin_username', $params['username']);
            cache('configs', NULL);
            cookie('admin_token', null);
            return json(['code' => 0]);
        }
        $mod = input('param.mod', 'sys');
        View::assign('mod', $mod);
        View::assign('conf', config('sys'));
        return view();
    }

    public function cleancache()
    {
        Cache::clear();
        return json(['code' => 0, 'msg' => 'succ']);
    }

    public function doc()
    {
        View::assign('siteurl', request()->root(true));
        return view();
    }
}
