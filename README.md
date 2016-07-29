# SimpleFork

一个最精简的php多进程控制库。它不依赖任何扩展以及其它的库，可以让你方便地利用系统的多个cpu来完成一些异步任务。我们封装了主进程和子进程之间的通信，以及日志打印，还有错误处理。

## 如何使用

### 安装

因为只有一个文件，你可以将其拷贝到任意位置。

### 使用

```php
$sf = new SimpleFork(2, 'my-process'); // 2代表子进程数, 'my-process'是进程的名字

$sf->master(function ($sf) {
  // 主进程的方法请包裹在master里
  while ($sf->loop(100000)) { // 100000为等待的微秒数
    $sf->submit('http://www.google.cn/', function ($data) { // 使用submit方法将其提交到一个空闲的进程，如果没有空闲的，系统会自动等待
      echo $data;
    });
  }
})->slave(function ($url, $sf) {
  $sf->log('fetch %s', $url); // 使用内置的log方法，子进程的log也会被打印到主进程里
  return http_request($url); // 直接返回数据，主进程将在回调中收到
});
```
