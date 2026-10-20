#!/bin/sh
set -e

# 安装状态（.env）和 ThinkPHP 运行时缓存都放在 /data，容器重建后还在
mkdir -p /data/runtime

if [ ! -L /var/www/html/.env ]; then
    rm -f /var/www/html/.env
    ln -s /data/.env /var/www/html/.env
fi

if [ -d /var/www/html/runtime ] && [ ! -L /var/www/html/runtime ]; then
    rm -rf /var/www/html/runtime
    ln -s /data/runtime /var/www/html/runtime
fi

# 官方镜像把 /var/www/html 建成 1777（sticky + 全局可写）。内核
# protected_symlinks 会因此拒绝 www-data 跟随该目录下 root 所有的符号链接，
# 表现是 .env 明明存在、PHP 却 file_exists()=false，安装页一直重复出现。
chmod 755 /var/www/html

# /data 是宿主机挂进来的目录，属主不一定是 www-data
chown -R www-data:www-data /data 2>/dev/null || true
chown -h www-data:www-data /var/www/html/.env /var/www/html/runtime 2>/dev/null || true

exec docker-php-entrypoint "$@"
