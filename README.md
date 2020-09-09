# PHP-Resque

在 [chrisboulton/php-resque](https://github.com/chrisboulton/php-resque) 的基础上进行了如下改造：

- 采用psr-4自动加载规范
- 合并 php-resque-scheduler
- 新增自定义处理方法worker
- 支持自定义worker
- 支持redis扩展及Predis扩展
- 支持定时器功能
- 支持ThinkPHP5/6命令行使用

## 安装

```bash
composer require hectorqin/php-resque
```

## 使用

### 启动worker

```bash
# 测试
./vendor/bin/resque

# ThinkPHP使用
php think resque start
```

