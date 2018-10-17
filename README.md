# xhprof-collector

Single XHProf collector use [XHProf](https://github.com/phacility/xhprof) API.

Notice that this is a collector only, so you have to get a gui to show collected data such as [xhgui](https://github.com/perftools/xhgui).

## Tested php version

* 5.6.36
* 7.0.30

> Notice:If you are running php under Kubernetes, you have to use tideways_xhprof extension which only support php >= 7.0 to avoid XHProf crash in hp_execute_internal. 

## Require

### XHProf extension(either)

* [uprofiler](https://github.com/FriendsOfPHP/uprofiler)
* tideways
* [tideways_xhprof](https://github.com/tideways/php-xhprof-extension)(recommend)
* [xhprof](https://github.com/phacility/xhprof)

### mongo extension(either)

* [mongodb](http://php.net/manual/zh/mongodb.installation.php)(recommend)
* [mongo](http://php.net/manual/zh/mongo.installation.php)

## Symfony Integration Example

### Include(either)
 
* Composer(recommend)

```
{
  "require" : {
    "zoa-chou/xhprof-collector": "*",
  }
}


```

* Single file

1. Copy xhprof-collector/src/collector.php to your path
2. Require collector to your project at first line, such as:

```php
<?php
require_once '/path/to/your/collector.php';
```

* Nginx configure

1. Copy xhprof-collector/src/collector.php to your path
2. Add fastcgi_param to your nginx config inside server block, such as:

```
location ~ .*\.php?$ {
    fastcgi_param PHP_VALUE "auto_prepend_file=/path/to/your/collector.php";
    fastcgi_pass  127.0.0.1:9000;
    fastcgi_index index.php;
    include fcgi.conf;
}
```

> Notice:Once you visited the host which server configure collector, nginx will always send fastcgi_param to php-fpm even if you visit other not configure server.

* Append php.ini 

1. Copy xhprof-collector/src/collector.php to your path
2. Add auto_prepend_file to your php.ini, such as:

```ini
auto_prepend_file=/path/to/your/collector.php
```

### Configure environment variables

* XHGUI_ENABLE_PROB —— The probability of start collector while request.Valid value is between 0(off) and 100(all on), default is 0.
* XHGUI_MONGO_URI —— mongodb uri, such as：```mongodb://username:password@ip:host,ip2:host2/dbname?connectTimeoutMS=200```
* XHGUI_ENABLE_CLI —— Enable collector while php running as cli model.Valid values is 0(off) and 1(on), default is 0.
* XHGUI_SINGLE_CONTROL —— Enable use http header XHGUI-ENABLE-PROB (just like XHGUI_ENABLE_PROB, such as: 'XHGUI-ENABLE-PROB: 100') to control the probability of start collector which will cover XHGUI_ENABLE_PROB. Valid values is 0(off) and 1(on), default is 0.
