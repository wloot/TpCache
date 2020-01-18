## TpCache 魔改版

让 `typecho` 支持 `memcached` 和 `redis` 缓存器

了解详情: https://candypanic.cn/works/35.html

原插件地址: https://github.com/phpgao/TpCache

### 缓存更新机制

**目前以下操作会触发缓存更新**

- 来自原生评论系统的评论
- 后台文章或页面更新
- 后台更新评论
- 重启缓存器后端
- 缓存到期

## 安装

请将文件夹**重命名**为`TpCache`。再拷贝至`usr/plugins/下`。

## 升级

请先**禁用此插件**后再升级，很多莫名其妙的问题都是因为没有先禁用而直接升级导致的！
