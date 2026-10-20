## 微信公众号多域名回调系统

这是一款基于ThinkPHP开发的微信公众号多域名回调系统。本系统有如下功能：

- 微信公众号多域名回调功能：微信公众号后台默认只能授权2个网页域名，用本系统突破这个限制，用同一个公众号对接无限多个网站。网站后台支持回调域名白名单的管理，以及登录记录的查看。
- ~~微信access_token获取功能~~（建议改用微信新出的稳定版token）。
- 微信消息事件转发功能：微信公众平台/企业微信的服务器设置只能填写1个url，用本系统可以同时转发给多个url。如用户关注事件、用户发送的消息等，可以同时转发给到多个站点。
- 支付宝开放平台多域名回调功能：每个支付宝开发平台应用只能配置1个授权回调域名，用本系统突破这个限制，可同时在多个网站使用同一个支付宝开放平台应用。
- QQ互联多域名回调功能：每个QQ互联应用只能配置1个网站回调地址，用本系统突破这个限制，可同时在多个网站使用同一个QQ互联应用。

### 部署方法

* 运行环境要求PHP8.0+，MySQL5.6+
* 设置网站运行目录为`public`
* 设置伪静态为`ThinkPHP`
* 访问网站，会自动跳转到安装页面，根据提示安装完成
* 访问 /admin 进入后台管理

##### 伪静态规则

* Nginx

```
location / {
	if (!-e $request_filename){
		rewrite  ^(.*)$  /index.php?s=$1  last;   break;
	}
}
```

* Apache

```
<IfModule mod_rewrite.c>
  Options +FollowSymlinks -Multiviews
  RewriteEngine On

  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(.*)$ index.php/$1 [QSA,PT,L]
</IfModule>
```

### Docker 部署

需要 Docker 与 Docker Compose 插件，镜像内置 PHP 8.3 + Apache，不用另外配置 PHP 和伪静态。

也可以直接用构建好的镜像（GitHub Actions 手动构建，标签为 `v` + 上游版本号，支持 amd64 / arm64）：

```bash
docker pull ghcr.io/ldxw/wxredirect:v1.7
```

```bash
cp .env.docker.example .env    # 修改端口与数据库密码
mkdir -p data
docker compose up -d --build
```

启动后访问 `http://服务器IP:8080`（端口由 `.env` 里的 `APP_PORT` 决定），按页面提示完成安装：

| 安装项 | 填写内容 |
|---|---|
| 数据库地址 | `db` |
| 数据库端口 | `3306` |
| 数据库名 | `.env` 里的 `DB_NAME` |
| 数据库用户名 | `.env` 里的 `DB_USER` |
| 数据库密码 | `.env` 里的 `DB_PASSWORD` |
| 表前缀 | 默认 `cloud_` 即可 |

如果安装页提示 **连接数据库失败：数据库地址填写错误**，通常是这两种情况：

| 现象 | 原因 | 处理 |
|---|---|---|
| 默认 `localhost` 报错 | 容器里的 localhost 是容器自己，不是数据库 | 地址填 `db` |
| 填 `db` 也报错 | 只单独 `docker run` 了镜像，没有起数据库 | 用本仓库的 compose 起整套，或把地址换成你已有的 MySQL 地址 |

自检：

```bash
docker compose ps                                  # 两个服务都要 Up/healthy
docker exec wxredirect getent hosts db             # 能解析出 IP
docker exec wxredirect-db mysql -uroot -p"$DB_ROOT_PASSWORD" -e "select 1"
```

改过 `.env` 里的 `DB_PASSWORD` 但之前已经启动过，数据库卷里还是旧密码，需要用新密码或清空卷重来。

安装状态保存在宿主机 `./data/.env`，数据库在 `db_data` 卷里，`docker compose down` 之后重新 `up`
不需要重新安装。要重装就删掉 `data/.env` 并清空数据库卷：

```bash
docker compose down -v
rm -f data/.env
```

### 后台 QQ 一键登录

后台支持用 QQ 授权登录，适合「申请 QQ 互联」时给审核人员看，也不影响原来的账号密码登录。

1. 到 [QQ 互联](https://connect.qq.com/) 申请「网站应用」，回调地址填 `https://你的域名/return`
   —— 和本系统原有的 QQ 回调是同一个地址，后台设置页会直接显示这一串，可复制。
   审核期间就能拿到 AppID / AppKey 用于调试。
2. 后台 → 右上角「管理员设置」→「QQ 一键登录」，填 AppID / AppKey，勾选「启用」，保存。
3. 保存后登录页立即出现「QQ 一键登录」按钮，**不需要先绑定 QQ**。
4. 第一次用 QQ 登录时，会自动把这个 QQ 绑定为管理员（「允许第一个登录的 QQ 自动绑定」默认开启），
   方便审核人员点进去就能完成授权并进入后台。

说明：

- 已经绑定过之后，只有绑定的那个 QQ 能登录；换号或解绑都要先登录进来操作；
- 绑定完成后建议把「自动绑定」关掉，避免别人抢先绑定；
- 没启用、没填 AppID/AppKey 时登录页不显示按钮；
- QQ 登录走 QQ 互联的授权校验，不经过图形验证码；
- 后台登录和「多域名回调」共用 `域名/return` 这个回调，靠 state 区分：state 命中管理员会话就走登录，
  否则按原来的第三方回调转发处理。

## 支持与反馈

🌐 作者信息：消失的彩虹海([https://blog.cccyun.cn](https://blog.cccyun.cn/))

⭐ 如果您觉得本项目对您有帮助，欢迎给项目点个 Star

### 其他推荐

- [彩虹云主机 - 免备案CDN/虚拟主机](https://www.cccyun.net/)
- [小白云高防云服务器](https://www.xiaobaiyun.cn/aff/GMLPMFOV)
