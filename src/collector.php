<?php
/**
 * User: ZoaChou
 */

if (!defined('_XHGUI_INIT')) {
    /**
     * Get environment variable
     * @param $key
     * @param string $default
     * @return string
     */
    function _xhguiGetEnv($key,$default='')
    {
        return isset($_SERVER[$key]) ? $_SERVER[$key] : $default;
    }

    $_xhguiEnableProb = intval(_xhguiGetEnv('XHGUI_ENABLE_PROB',0));

    // Check if enable single control on http request
    $_xhguiSingleEnableProb = _xhguiGetEnv('HTTP_XHGUI_ENABLE_PROB',null);
    if (_xhguiGetEnv('XHGUI_SINGLE_CONTROL',0) && !is_null($_xhguiSingleEnableProb)) {
        $_xhguiEnableProb = intval($_xhguiSingleEnableProb);
    }

    // Check if close cli collector
    if (!_xhguiGetEnv('XHGUI_ENABLE_CLI',0) && php_sapi_name() == "cli") {
        $_xhguiEnableProb = 0;
    }

    if ($_xhguiEnableProb && $_xhguiEnableProb >= mt_rand(0,100)) {
        if (extension_loaded('uprofiler')) {
            uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY, array());
        } else if (extension_loaded('tideways')) {
            tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_NO_SPANS, array());
        } elseif (extension_loaded('tideways_xhprof')) {
            tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_CPU | TIDEWAYS_XHPROF_FLAGS_MEMORY);
        } elseif (extension_loaded('xhprof')) {
            if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
                xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS, array());
            } else {
                xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY, array());
            }
        } else {
            // Close collector if not any xhprof extension support
            error_log('xhprof extension not exist');
            $_xhguiEnableProb = 0;
        }

        if (!extension_loaded('mongodb') && !extension_loaded('mongo')) {
            // Close collector if not any mongo extension support
            error_log('mongo extension not exist');
            $_xhguiEnableProb = 0;
        }

        if ($_xhguiEnableProb) {
            register_shutdown_function(function () {
                if (extension_loaded('uprofiler')) {
                    $data['profile'] = uprofiler_disable();
                } else if (extension_loaded('tideways')) {
                    $data['profile'] = tideways_disable();
                } elseif (extension_loaded('tideways_xhprof')) {
                    $data['profile'] = tideways_xhprof_disable();
                } else {
                    $data['profile'] = xhprof_disable();
                }

                // ignore_user_abort(true) allows your PHP script to continue executing,
                // even if the user has terminated their request.
                // Further Reading: http://blog.preinheimer.com/index.php?/archives/248-When-does-a-user-abort.html
                ignore_user_abort(true);

                // Try to send any data remaining in the output buffers
                // and close user request to avoid making the user wait
                fastcgi_finish_request();

                $uri = array_key_exists('REQUEST_URI', $_SERVER)
                    ? $_SERVER['REQUEST_URI']
                    : null;
                if (empty($uri) && isset($_SERVER['argv'])) {
                    $cmd = basename($_SERVER['argv'][0]);
                    $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
                }

                $time = array_key_exists('REQUEST_TIME', $_SERVER)
                    ? $_SERVER['REQUEST_TIME']
                    : time();

                // In some cases there is comma instead of dot
                $delimiter = (strpos($_SERVER['REQUEST_TIME_FLOAT'], ',') !== false) ? ',' : '.';
                $requestTimeFloat = explode($delimiter, $_SERVER['REQUEST_TIME_FLOAT']);
                if (!isset($requestTimeFloat[1])) {
                    $requestTimeFloat[1] = 0;
                }

                if (extension_loaded('mongodb')) {
                    // Use mongodb extension
                    $requestTs = new \MongoDB\BSON\UTCDateTime($time * 1000);
                    $requestTsMicro = new \MongoDB\BSON\UTCDateTime(
                        round(($requestTimeFloat[0] . $requestTimeFloat[1]) / 10)
                    );
                } else {
                    // Use mongo extension
                    $requestTs = new \MongoDate($time);
                    $requestTsMicro = new \MongoDate($requestTimeFloat[0], $requestTimeFloat[1]);
                }

                $server = $_SERVER;
                // Remove XHGUI_MONGO_URI for security reasons
                unset($server['XHGUI_MONGO_URI']);

                $data['meta'] = array(
                    'url' => $uri,
                    'SERVER' => $server,
                    'get' => $_GET,
                    'env' => $_ENV,
                    'simple_url' => preg_replace('/\=\d+/', '', $uri),
                    'request_ts' => $requestTs,
                    'request_ts_micro' => $requestTsMicro,
                    'request_date' => date('Y-m-d', $time),
                );

                $uri = _xhguiGetEnv('XHGUI_MONGO_URI','mongodb://127.0.0.1:27017');
                $uriInfo = parse_url($uri);
                $queryInfo = array();
                if (isset($uriInfo['query'])) {
                    parse_str($uriInfo['query'],$queryInfo);
                }

                // Set a default value to avoid request is blocked by mongodb connect abnormal
                // if connect timeout not set
                if (!isset($queryInfo['connectTimeoutMS'])) {
                    $queryInfo['connectTimeoutMS'] = 1000;
                }
                $dbName = substr($uriInfo['path'],1);
                if (isset($uriInfo['user']) && $uriInfo['user']) {
                    $mongoUri = sprintf(
                        '%s://%s:%s@%s:%s/%s?%s',
                        $uriInfo['scheme'],
                        $uriInfo['user'],
                        $uriInfo['pass'],
                        $uriInfo['host'],
                        isset($uriInfo['port']) ? $uriInfo['port'] : 27017,
                        $dbName,
                        http_build_query($queryInfo)
                    );
                } else {
                    // Support not username mongo uri
                    $mongoUri = sprintf(
                        '%s://%s:%s/%s?%s',
                        $uriInfo['scheme'],
                        $uriInfo['host'],
                        isset($uriInfo['port']) ? $uriInfo['port'] : 27017,
                        $dbName,
                        http_build_query($queryInfo)
                    );
                }

                try {
                    if (extension_loaded('mongodb')) {
                        // Use mongodb extension
                        $manager = new \MongoDB\Driver\Manager(
                            $mongoUri,
                            array(
                                // Set socketTimeoutMS to avoid request is blocked by mongodb write abnormal
                                'socketTimeoutMS' => $queryInfo['connectTimeoutMS'],
                            )
                        );
                        $bulk = new \MongoDB\Driver\BulkWrite();
                        $bulk->insert($data);
                        $manager->executeBulkWrite(
                            $dbName.'.results',
                            $bulk
                        );
                    } else {
                        // Use mongo extension
                        $manager = new \MongoClient(
                            $mongoUri,
                            array(
                                'connect' => true,
                                // Set socketTimeoutMS to avoid request is blocked by mongodb write abnormal
                                'socketTimeoutMS' => $queryInfo['connectTimeoutMS'],
                            )
                        );
                        $collection = $manager->selectCollection($dbName, 'results');
                        $data['_id'] = new \MongoId();
                        $collection->insert($data);
                    }
                } catch (\Exception $exception) {
                    error_log('xhgui - ' . $exception->getMessage());
                }

            });
        }
    }

    define('_XHGUI_INIT',true);
}
