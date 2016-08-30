<?php

/**
 * SimpleFork
 *
 * @copyright Copyright (c) 2016 SegmentFault Team. (http://segmentfault.com)
 * @author Joyqi <joyqi@segmentfault.com>
 * @license MIT License
 */
class SimpleFork {
    /**
     * @var array
     */
    private $_processes = [];

    /**
     * @var bool
     */
    private $_isForked = false;

    /**
     * @var string
     */
    private $_cmd;

    /**
     * @var string
     */
    private $_name;

    /**
     * @var int
     */
    private $_limit = 2;

    /**
     * @var int
     */
    private $_busy = 0;

    /**
     * @var callable
     */
    private $_masterHandler = NULL;

    /**
     * @var callable
     */
    private $_slaveHandler = NULL;

    /**
     * SimpleFork constructor.
     * @param int $limit
     * @param string $name
     */
    public function __construct($limit = 2, $name = 'SimpleFork')
    {
        $opt = getopt('m:');
        $this->_name = $name;
        $this->_limit = $limit;
        
        if (isset($opt['m']) && $opt['m'] == 'slave') {
            $this->_isForked = true;
        }
    }

    /**
     * @param callable $masterHandler
     * @return $this
     */
    public function master(callable $masterHandler)
    {
        if (!$this->_isForked) {
            $this->_masterHandler = $masterHandler;
            $this->createMaster($this->_limit);
        }
        
        return $this;
    }

    /**
     * @param callable $slaveHandler
     * @return $this
     */
    public function slave(callable $slaveHandler)
    {
        if ($this->_isForked) {
            $this->_slaveHandler = $slaveHandler;
            $this->createSlave();
        }
        
        return $this;
    }

    /**
     * submit task
     *
     * @param $data
     * @param callable $cb
     */
    public function submit($data = NULL, $cb = NULL)
    {
        if (!$this->_isForked) {
            $process = &$this->getAvailableProcess();
            $process['cb'] = $cb;
            $data = json_encode($data);
            $length = strlen($data);
            $length = str_pad($length . '', 8, ' ', STR_PAD_RIGHT);
            
            // write head
            fwrite($process['pipes'][0], $length . $data);
        }
    }

    /**
     * @param int $sleep
     * @return bool
     */
    public function loop($sleep = 0)
    {
        if (!$this->_isForked) {
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
            
            $this->check();
            return true;
        }
        
        return false;
    }

    /**
     * @param int $timeout
     */
    public function wait($timeout = 0)
    {
        $start = microtime(true);
        
        while (true) {
            $this->check();
            $interval = (microtime(true) - $start) * 1000;
            
            if ($this->_busy == 0) {
                return;
            }
            
            // timeout
            if ($timeout > 0 && $interval >= $timeout) {
                $this->killallBusyProcesses();
                return;
            }
            
            usleep(10000);
        }
    }

    /**
     * @param $str
     */
    public function log($str)
    {
        $args = func_get_args();
        $line = count($args) > 1 ? call_user_func_array('sprintf', $args) : $str;
        
        $line = date('Y-m-d H:i:s') . ' [' . ($this->_isForked ? 'slave' : 'master') 
            . ':' . getmypid() . '] ' . $line;
        
        error_log($line . "\n", 3, $this->_isForked ? 'php://stderr' : 'php://stdout');
    }

    /**
     * create master handlers
     * @param $limit
     */
    private function createMaster($limit)
    {
        $this->_cmd = $this->getCmd();
        
        for ($i = 0; $i < $limit; $i ++) {
            $this->_processes[] = $this->createProcess();
        }
        
        @cli_set_process_title($this->_name . ':' . 'master');
        
        if (!empty($this->_masterHandler)) {
            call_user_func($this->_masterHandler, $this);
        }
    }

    /**
     * create slave handlers
     */
    private function createSlave()
    {
        @cli_set_process_title($this->_name . ':' . 'slave');
        file_put_contents('php://stdout', str_pad(getmypid(), 5, ' ', STR_PAD_LEFT));
        
        while (true) {
            $fp = @fopen('php://stdin', 'r');
            $recv = @fread($fp, 8);
            $size = intval(rtrim($recv));
            $data = @fread($fp, $size);
            @fclose($fp);
            
            if (!empty($data)) {
                if (!empty($this->_slaveHandler)) {
                    $data = json_decode($data, true);
                    $resp = call_user_func($this->_slaveHandler, $data, $this);
                    echo json_encode($resp);
                }
            } else {
                usleep(100000);
            }
        }
    }

    /**
     * @return array
     */
    private function createProcess()
    {
        $desc = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w']
        ];

        $res = proc_open($this->_cmd, $desc, $pipes, getcwd());
        $pid = ltrim(stream_get_contents($pipes[1], 5));

        $process = [
            'res'   =>  $res,
            'pipes' =>  $pipes,
            'status'=>  true,
            'pid'   =>  $pid,
            'cb'    =>  NULL
        ];

        stream_set_blocking($pipes[1], 0);
        stream_set_blocking($pipes[2], 0);
        
        $this->log('start ' . $pid);

        return $process;
    }


    /**
     * @return int|string
     */
    private function check()
    {
        $index = -1;

        foreach ($this->_processes as $key => &$process) {
            $this->checkProcessAlive($process);

            if (!$process['status']) {
                echo stream_get_contents($process['pipes'][2]);
                $result = stream_get_contents($process['pipes'][1]);

                if (!empty($result)) {
                    $process['status'] = true;
                    $this->_busy --;
                    
                    if (!empty($process['cb'])) {
                        $process['cb'](json_decode($result, true));
                    }
                }
            }

            if ($process['status'] && $index < 0) {
                $index = $key;
            }
        }

        return $index;
    }

    /**
     * @param $process
     */
    private function checkProcessAlive(&$process)
    {
        $status = proc_get_status($process['res']);

        if (!$status['running']) {
            echo stream_get_contents($process['pipes'][2]);
            
            @proc_close($process['res']);
            $this->log('close ' . $process['pid']);

            if (!$process['status']) {
                $this->_busy --;
            }

            $process = $this->createProcess();
        }
    }

    /**
     * kill all
     */
    private function killallBusyProcesses()
    {
        foreach ($this->_processes as &$process) {
            if (!$process['status']) {
                @proc_close($process['res']);
                $this->log('close ' . $process['pid']);
                $process = $this->createProcess();
                $this->_busy --;
            }
        }
    }

    /**
     * @return null
     */
    private function &getAvailableProcess()
    {
        $available = NULL;

        while (true) {
            $index = $this->check();

            if (isset($this->_processes[$index])) {
                $this->_processes[$index]['status'] = false;
                $this->_busy ++;
                return $this->_processes[$index];
            }

            // sleep 50 msec
            usleep(50000);
        }
    }
    
    /**
     * @return string
     */
    private function getCmd()
    {
        $prefix = isset($_SERVER['_']) ? $_SERVER['_'] : '/usr/bin/env php';
        return $prefix . ' ' . $_SERVER['PHP_SELF'] . ' -mslave';
    }
}
