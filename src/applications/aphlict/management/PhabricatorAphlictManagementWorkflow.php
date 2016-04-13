<?php

abstract class PhabricatorAphlictManagementWorkflow
  extends PhabricatorManagementWorkflow {

  private $debug = false;
  private $configPath;

  final protected function setDebug($debug) {
    $this->debug = $debug;
    return $this;
  }

  protected function getLaunchArguments() {
    return array(
      array(
        'name' => 'config',
        'param' => 'file',
        'help' => pht(
          'Use a specific configuration file instead of the default '.
          'configuration.'),
      ),
    );
  }

  protected function parseLaunchArguments(PhutilArgumentParser $args) {
    $config_file = $args->getArg('config');
    if ($config_file) {
      $full_path = Filesystem::resolvePath($config_file);
      $show_path = $full_path;
    } else {
      $root = dirname(dirname(phutil_get_library_root('phabricator')));

      $try = array(
        'phabricator/conf/aphlict/aphlict.custom.json',
        'phabricator/conf/aphlict/aphlict.default.json',
      );

      foreach ($try as $config) {
        $full_path = $root.'/'.$config;
        $show_path = $config;
        if (Filesystem::pathExists($full_path)) {
          break;
        }
      }
    }

    echo tsprintf(
      "%s\n",
      pht(
        'Reading configuration from: %s',
        $show_path));

    try {
      $data = Filesystem::readFile($full_path);
    } catch (Exception $ex) {
      throw new PhutilArgumentUsageException(
        pht(
          'Failed to read configuration file. %s',
          $ex->getMessage()));
    }

    try {
      $data = phutil_json_decode($data);
    } catch (Exception $ex) {
      throw new PhutilArgumentUsageException(
        pht(
          'Configuration file is not properly formatted JSON. %s',
          $ex->getMessage()));
    }

    try {
      PhutilTypeSpec::checkMap(
        $data,
        array(
          'servers' => 'list<wild>',
        ));
    } catch (Exception $ex) {
      throw new PhutilArgumentUsageException(
        pht(
          'Configuration file has improper configuration keys at top '.
          'level. %s',
          $ex->getMessage()));
    }

    $servers = $data['servers'];
    $has_client = false;
    $has_admin = false;
    $port_map = array();
    foreach ($servers as $index => $server) {
      PhutilTypeSpec::checkMap(
        $server,
        array(
          'type' => 'string',
          'port' => 'int',
          'listen' => 'optional string|null',
          'ssl.key' => 'optional string|null',
          'ssl.cert' => 'optional string|null',
        ));

      $port = $server['port'];
      if (!isset($port_map[$port])) {
        $port_map[$port] = $index;
      } else {
        throw new PhutilArgumentUsageException(
          pht(
            'Two servers (at indexes "%s" and "%s") both bind to the same '.
            'port ("%s"). Each server must bind to a unique port.',
            $port_map[$port],
            $index,
            $port));
      }

      $type = $server['type'];
      switch ($type) {
        case 'admin':
          $has_admin = true;
          break;
        case 'client':
          $has_client = true;
          break;
        default:
          throw new PhutilArgumentUsageException(
            pht(
              'A specified server (at index "%s", on port "%s") has an '.
              'invalid type ("%s"). Valid types are: admin, client.',
              $index,
              $port,
              $type));
      }

      $ssl_key = idx($server, 'ssl.key');
      $ssl_cert = idx($server, 'ssl.cert');
      if (($ssl_key && !$ssl_cert) || ($ssl_cert && !$ssl_key)) {
        throw new PhutilArgumentUsageException(
          pht(
            'A specified server (at index "%s", on port "%s") specifies '.
            'only one of "%s" and "%s". Each server must specify neither '.
            '(to disable SSL) or specify both (to enable it).',
            $index,
            $port,
            'ssl.key',
            'ssl.cert'));
      }
    }

    if (!$servers) {
      throw new PhutilArgumentUsageException(
        pht(
          'Configuration file does not specify any servers. This service '.
          'will not be able to interact with the outside world if it does '.
          'not listen on any ports. You must specify at least one "%s" '.
          'server and at least one "%s" server.',
          'admin',
          'client'));
    }

    if (!$has_client) {
      throw new PhutilArgumentUsageException(
        pht(
          'Configuration file does not specify any client servers. This '.
          'service will be unable to transmit any notifications without a '.
          'client server. You must specify at least one server with '.
          'type "%s".',
          'client'));
    }

    if (!$has_admin) {
      throw new PhutilArgumentUsageException(
        pht(
          'Configuration file does not specify any administrative '.
          'servers. This service will be unable to receive messages. '.
          'You must specify at least one server with type "%s".',
          'admin'));
    }

    $this->configPath = $full_path;
  }

  final public function getPIDPath() {
    $path = PhabricatorEnv::getEnvConfig('notification.pidfile');

    try {
      $dir = dirname($path);
      if (!Filesystem::pathExists($dir)) {
        Filesystem::createDirectory($dir, 0755, true);
      }
    } catch (FilesystemException $ex) {
      throw new Exception(
        pht(
          "Failed to create '%s'. You should manually create this directory.",
          $dir));
    }

    return $path;
  }

  final public function getLogPath() {
    $path = PhabricatorEnv::getEnvConfig('notification.log');

    try {
      $dir = dirname($path);
      if (!Filesystem::pathExists($dir)) {
        Filesystem::createDirectory($dir, 0755, true);
      }
    } catch (FilesystemException $ex) {
      throw new Exception(
        pht(
          "Failed to create '%s'. You should manually create this directory.",
          $dir));
    }

    return $path;
  }

  final public function getPID() {
    $pid = null;
    if (Filesystem::pathExists($this->getPIDPath())) {
      $pid = (int)Filesystem::readFile($this->getPIDPath());
    }
    return $pid;
  }

  final public function cleanup($signo = '?') {
    global $g_future;
    if ($g_future) {
      $g_future->resolveKill();
      $g_future = null;
    }

    Filesystem::remove($this->getPIDPath());

    exit(1);
  }

  public static function requireExtensions() {
    self::mustHaveExtension('pcntl');
    self::mustHaveExtension('posix');
  }

  private static function mustHaveExtension($ext) {
    if (!extension_loaded($ext)) {
      echo pht(
        "ERROR: The PHP extension '%s' is not installed. You must ".
        "install it to run Aphlict on this machine.",
        $ext)."\n";
      exit(1);
    }

    $extension = new ReflectionExtension($ext);
    foreach ($extension->getFunctions() as $function) {
      $function = $function->name;
      if (!function_exists($function)) {
        echo pht(
          'ERROR: The PHP function %s is disabled. You must '.
          'enable it to run Aphlict on this machine.',
          $function.'()')."\n";
        exit(1);
      }
    }
  }

  final protected function willLaunch() {
    $console = PhutilConsole::getConsole();

    $pid = $this->getPID();
    if ($pid) {
      throw new PhutilArgumentUsageException(
        pht(
          'Unable to start notifications server because it is already '.
          'running. Use `%s` to restart it.',
          'aphlict restart'));
    }

    if (posix_getuid() == 0) {
      throw new PhutilArgumentUsageException(
        pht('The notification server should not be run as root.'));
    }

    // Make sure we can write to the PID file.
    if (!$this->debug) {
      Filesystem::writeFile($this->getPIDPath(), '');
    }

    // First, start the server in configuration test mode with --test. This
    // will let us error explicitly if there are missing modules, before we
    // fork and lose access to the console.
    $test_argv = $this->getServerArgv();
    $test_argv[] = '--test=true';


    execx('%C', $this->getStartCommand($test_argv));
  }

  private function getServerArgv() {
    $log = $this->getLogPath();

    $server_argv = array();
    $server_argv[] = '--config='.$this->configPath;
    $server_argv[] = '--log='.$log;

    return $server_argv;
  }

  final protected function launch() {
    $console = PhutilConsole::getConsole();

    if ($this->debug) {
      $console->writeOut(
        "%s\n",
        pht('Starting Aphlict server in foreground...'));
    } else {
      Filesystem::writeFile($this->getPIDPath(), getmypid());
    }

    $command = $this->getStartCommand($this->getServerArgv());

    if (!$this->debug) {
      declare(ticks = 1);
      pcntl_signal(SIGINT, array($this, 'cleanup'));
      pcntl_signal(SIGTERM, array($this, 'cleanup'));
    }
    register_shutdown_function(array($this, 'cleanup'));

    if ($this->debug) {
      $console->writeOut(
        "%s\n\n    $ %s\n\n",
        pht('Launching server:'),
        $command);

      $err = phutil_passthru('%C', $command);
      $console->writeOut(">>> %s\n", pht('Server exited!'));
      exit($err);
    } else {
      while (true) {
        global $g_future;
        $g_future = new ExecFuture('exec %C', $command);
        $g_future->resolve();

        // If the server exited, wait a couple of seconds and restart it.
        unset($g_future);
        sleep(2);
      }
    }
  }


/* -(  Commands  )----------------------------------------------------------- */


  final protected function executeStartCommand() {
    $console = PhutilConsole::getConsole();
    $this->willLaunch();

    $pid = pcntl_fork();
    if ($pid < 0) {
      throw new Exception(
        pht(
          'Failed to %s!',
          'fork()'));
    } else if ($pid) {
      $console->writeErr("%s\n", pht('Aphlict Server started.'));
      exit(0);
    }

    // When we fork, the child process will inherit its parent's set of open
    // file descriptors. If the parent process of bin/aphlict is waiting for
    // bin/aphlict's file descriptors to close, it will be stuck waiting on
    // the daemonized process. (This happens if e.g. bin/aphlict is started
    // in another script using passthru().)
    fclose(STDOUT);
    fclose(STDERR);

    $this->launch();
    return 0;
  }


  final protected function executeStopCommand() {
    $console = PhutilConsole::getConsole();

    $pid = $this->getPID();
    if (!$pid) {
      $console->writeErr("%s\n", pht('Aphlict is not running.'));
      return 0;
    }

    $console->writeErr("%s\n", pht('Stopping Aphlict Server (%s)...', $pid));
    posix_kill($pid, SIGINT);

    $start = time();
    do {
      if (!PhabricatorDaemonReference::isProcessRunning($pid)) {
        $console->writeOut(
          "%s\n",
          pht('Aphlict Server (%s) exited normally.', $pid));
        $pid = null;
        break;
      }
      usleep(100000);
    } while (time() < $start + 5);

    if ($pid) {
      $console->writeErr("%s\n", pht('Sending %s a SIGKILL.', $pid));
      posix_kill($pid, SIGKILL);
      unset($pid);
    }

    Filesystem::remove($this->getPIDPath());
    return 0;
  }

  private function getNodeBinary() {
    if (Filesystem::binaryExists('nodejs')) {
      return 'nodejs';
    }

    if (Filesystem::binaryExists('node')) {
      return 'node';
    }

    throw new PhutilArgumentUsageException(
      pht(
        'No `%s` or `%s` binary was found in %s. You must install '.
        'Node.js to start the Aphlict server.',
        'nodejs',
        'node',
        '$PATH'));
  }

  private function getAphlictScriptPath() {
    $root = dirname(phutil_get_library_root('phabricator'));
    return $root.'/support/aphlict/server/aphlict_server.js';
  }

  private function getStartCommand(array $server_argv) {
    return csprintf(
      '%s %s %Ls',
      $this->getNodeBinary(),
      $this->getAphlictScriptPath(),
      $server_argv);
  }

}
