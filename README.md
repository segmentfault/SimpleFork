# SimpleFork

一个最精简的php多进程控制库。它不依赖任何扩展以及其它的库，可以让你方便地利用系统的多个cpu来完成一些异步任务。我们封装了主进程和子进程之间的通信，以及日志打印，还有错误处理。

## 如何使用

### 安装

因为只有一个文件，你可以将其拷贝到任意位置。

### 使用

#### 循环调用的时候(比如从任务队列获取任务常驻内存)

```php
$sf = new SimpleFork(2, 'my-process'); // 2代表子进程数, 'my-process'是进程的名字

$sf->master(function ($sf) {
    // 主进程的方法请包裹在master里
    while ($sf->loop(100)) { // 100为等待的毫秒数
        $sf->submit('http://www.google.cn/', function ($data) { // 使用submit方法将其提交到一个空闲的进程，如果没有空闲的，系统会自动等待
            echo $data;
        });
    }
})->slave(function ($url, $sf) {
    $sf->log('fetch %s', $url); // 使用内置的log方法，子进程的log也会被打印到主进程里
    return http_request($url);  // 直接返回数据，主进程将在回调中收到
});
```

#### 一次调用的时候(比如有一个很大的工作需要分片处理)

```
$sf = new SimpleFork(5, 'map-reduce');

$sf->master(function ($sf) {
    $sf->submit([0, 10000]);
    $sf->submit([10000, 20000]);
    $sf->submit([20000, 30000]);
    $sf->submit([30000, 40000]);
    $sf->submit([40000, 50000]);

    $sf->wait();    // 等待所有任务执行完毕, 可以带一个timeout参数代表超时时间毫秒数, 超过后将强行终止还没完成的任务并返回
})->slave(function ($params, $sf) {
    list ($from, $to) = $params;
    file_read_by_line($from, $to, 'example.txt');
});
```
