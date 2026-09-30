<?php

    namespace QueryServer;

    require 'ServerException.php';
    require 'ServerStatus.php';
    require 'ServerControl.php';

    use Ds\Set;
    use Socket;

    use RuntimeException;

    /** Runs the server at the given host address and port. 
     * The sock file is used to start, stop and restart the
     * server. By starting the server it is implied that 
     * incoming connections are accepted in a child process
     * which in turn forks and a spawned child process 
     * monitors the sock file for commands.
    */

    class QueryServer {

        // Server host address and port
        private string $host;
        private int $port;
        
        // Sock file
        private string $sockFilePath;

        // $serverSocket is created by the socket_create() function
        private Socket|bool $serverSocket;

        // $clientSocket is created by the socket_accept() function
        private \Socket|bool $clientSocket;       

        // The path to the lock file used before query processing
        private string $lockFilePath;

        // 30 seconds for timeout if no command is sent by the manager
        private static int $serverTimeout = 30; 

        /**
         * Construct a server object for processing queries
         * @param string $host The name or the IPv4 of the host for the server
         * @param int $port The server port to use a positive integer
         * */
        public function __construct(
            string $host, 
            int $port, 
            string $sockFilePath, 
            string $lockFilePath) {

            /** Server configuration variables. None of 
             * these variables can be null. 
             * */
            if (is_null($host) || is_null($port) || is_null($sockFilePath) || is_null($lockFilePath)) {
                $errorString = <<<EOD
                    None of the constructor arguments to the QueryServer can be null.
                    EOD;
                throw new RuntimeException($errorString);
            }

            $this->host = $host;
            $this->port = $port;    
            $this->sockFilePath = $sockFilePath;        
            $this->lockFilePath = $lockFilePath;

            // Create a TCP/IP socket to listen to
            $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if ($this->serverSocket === false) {
                throw new ServerException(
                    "socket_create() failed: " . socket_strerror(
                        socket_last_error($this->serverSocket)) . "\n",
                    Codes::SOCKET_CREATE_FAIL
                );
            }

            // Allow address reuse
            socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);            

            try {
                
                // Attempt to bind $serverSocket to the server port
                $this->bind();
                // Attempt to listen to connections using $serverSocket
                $this->listen();                
                
            } catch(ServerException $e) {
                echo "An error occurred: " . $e->getMessage() . "\n";
            }

        }
        /**
         * Wait until a TCP port is free before binding a socket.         
         * @param int    $timeoutSeconds Max time to wait before giving up
         * @param int    $checkIntervalSeconds How often to check
         * @return bool  True if port became free, false if timeout reached
         */
        private function acquirePort(int $timeoutSeconds = 30, 
            int $checkIntervalSeconds = 1) : bool
        {
            $startTime = time();

            while (true) {

                // Attempt to bind the socket to $this->port
                $socketBind = socket_bind($this->serverSocket, $this->host, $this->port);

                // Wait $checkIntervalSeconds to try again
                if ($socketBind === false)
                    sleep($checkIntervalSeconds);
                // Break out of the infinite loop on success
                else 
                    break;
                // Fail after $timeoutSeconds have passed and the port was not
                // available for binding
                if ((time() - $startTime) >= $timeoutSeconds) {
                    return false; // Timed out
                }                             
            }

            return true;            
        }

        public function __destruct() {              
            socket_close($this->serverSocket);

            /**
             * Kill all processes listening on a given TCP/UDP port.
             * Works on Linux/macOS. Requires PHP CLI and proper permissions.
             */

            $port = $this->port; // Change to your target port

            // Validate port number
            if (!is_int($port) || $port < 1 || $port > 65535) {
                die("Invalid port number.\n");
            }

            // Find PIDs using lsof
            exec("lsof -t -i :$port 2>/dev/null", $pids);

            if (empty($pids)) {
                echo "No process is listening on port $port.\n";
                exit(0);
            }

            // Kill each PID safely
            foreach ($pids as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    exec("kill -9 $pid 2>/dev/null", $output, $status);
                    if ($status === 0) {
                        echo "Killed process with PID: $pid\n";
                    } else {
                        echo "Failed to kill PID: $pid (may require sudo)\n";
                    }
                }
            }
        }

        private function bind() : void {            
            // Bind socket to IP and port
            if (!$this->acquirePort()) {
                throw new ServerException(
                    "socket_bind() failed: " . socket_strerror(
                        socket_last_error($this->serverSocket)) . "\n",
                    Codes::SOCKET_BIND_FAIL
                );
            }

            echo "Server successfully bound to : $this->host:$this->port \n";
        }

        private function listen() : void {
            // Start listening for connections
            if (!socket_listen($this->serverSocket)) {
                throw new ServerException(
                    "socket_listen() failed: " . socket_strerror(
                        socket_last_error($this->serverSocket)) . "\n",
                    Codes::SOCKET_LISTEN_FAIL
                );
            }

            echo "Server listening on $this->host:$this->port...\n"; 
        }

        /** Sock file JSON command format : 
         * {
         *   "command" : "accept" | "stop"   
         *   "request" :
         *   {
         *     "timestamp" : time,
         *     "clientId" : string,
         *     "requestId" : int,
         *     "hashAlgorithm" : string,
         *     "queries" : [
         *       {
         *         "executionOrder" : int,
         *         "queryId" : int,
         *         "queryBody" : string,
         *         "commandHash" : string         
         *       }, ...
         *     ]
         *   }
         * }
         */

        /** Read the sock file contents and parse the server command to 
         * execute. 
         * @return ?array Returns an associative array representing the JSON
         * command file or null on failure.
        */
        private function parseSockFile() : ?array {
            /** Read the sock file contents. */                                
            $sockFile = fopen($this->sockFilePath, 'r');
            
            if ($sockFile) {
                /** Keep track of the status of the file operations on the sock 
                 * file. */
                $sockFileStatus = true;

                /** Acquire a shared lock. */
                $sockFileStatus = flock($sockFile, LOCK_SH); 

                /** Read the entire file. */
                $sockFileContents = stream_get_contents($sockFile);

                /** Release the lock. */
                $sockFileStatus = flock($sockFile, LOCK_UN);

                /** Close the file handle. */
                $sockFileStatus = fclose($sockFile);

                /** Throw a RuntimeException if a file error occurred. */
                if (!$sockFileStatus || !$sockFileContents)
                    throw new RuntimeException(
                        "A file error occurred while parsing the contents of" .  
                        "the sock file."
                    );
                else 
                    return json_decode($sockFileContents, true);                                              
                
            } else {
                throw new RuntimeException("The sock file could not be read.");
            }            
        }

        /** Get the individual query headers.  
         * @return Set Returns a set whose entries are unique associative 
         * arrays of query headers.
         * @throws RuntimeException If the server command is not equal to 
         * "accept" in the sock file then query headers cannot be retrieved.
        */
        private function getQueryHeaders() : Set {
            /** Check whether the command is accept or otherwise. Query headers
             * can be retrieved only if the command is accept.
             */
            $serverCommand = $this->parseSockFile();

                if ($serverCommand["command"] !== "accept")
                    throw new RuntimeException(
                        "Cannot retrieve query headers if the command is not accept.",
                        ErrorCodes::ILLEGAL_SERVER_COMMAND->value
                    );
                else {

                    $request = $serverCommand["request"];
                    extract($request);                
                    $queryHeaders = new Set();
                    
                        foreach ($queries as $query) {
                            $queryHeaders->add([
                                "timestamp" => $timestamp,
                                "clientId" => $clientId,
                                "requestId" => $requestId,
                                "hashAlgorithm" => $hashAlgorithm,
                                "executionOrder" => $query["executionOrder"],
                                "queryId" => $query["queryId"],
                                "queryBody" => $query["queryBody"],
                                "commandHash" => $query["commandHash"]
                            ]);
                        }              

                }

            return $queryHeaders;
        }        

        /** Write the sock file. */
        private function writeSockFile(string $sockFileContents) {
            $sockFile = fopen($this->sockFilePath, 'w');

            if ($sockFile) {
                /** Acquire an exclusive lock. */
                if (flock($sockFile, LOCK_EX)) {
                    /** Keep track of the file operations using a status 
                     * variable. */
                    $sockFileStatus = true;

                    /** Truncate the file to overwrite it completely. */
                    $sockFileStatus = ftruncate($sockFile, 0);

                    /** Write the data. */
                    $sockFileStatus = fwrite(
                        $sockFile, 
                        $sockFileContents,
                        strlen($sockFileContents)
                    );

                    /** Flush output before releasing the lock. */
                    $sockFileStatus = fflush($sockFile);

                    /** Release the lock. */
                    $sockFileStatus = flock($sockFile, LOCK_UN);

                    /** Close the file handle. */
                    $sockFileStatus = fclose($sockFile);

                    if (!$sockFileStatus)
                        throw new RuntimeException(
                            "A file error occurred during sock file reception."
                        );

                    /** Throw an exception if acquiring a lock on the 
                     * file fails. */
                } else {
                    throw new RuntimeException(
                        "Failed to acquire a lock on the sock file."
                    );
                }
            }
        }

        /** Process the received queries and log using the $queryLogger which
         * takes as input the hash of the query and writes to the destination. 
         * The destination must implement the logging functionality and it may 
         * be a resource or a database interface.                     
         * @param callable $queryProcessor The callback to use for processing the 
         * queries.
         * @param callable $queryLogger Logs the computed hash of the query.
         * 
        */
        private function processQueries(                        
            callable $queryProcessor,
            callable $queryLogger) : bool {
            /** Accept the incoming connection. */        
            $this->clientSocket = socket_accept($this->serverSocket);

            /** Initially receive the length of the sock file then continue to
             * wait for the incoming connection to receive the contents of the 
             * sock file and parse it with parseSockFile and get query 
             * headers with getQueryHeaders.
             */
            socket_recv(
                $this->clientSocket, 
                $socketFileSize, 
                4096, 
                MSG_WAITALL
            );
            $socketFileSize = (int) $socketFileSize;

            if ($socketFileSize < 0)
                throw new RuntimeException(
                    "Received socket file size cannot be negative."
                );

            /** Accept the incoming connection. */        
            $this->clientSocket = socket_accept($this->serverSocket);

            /** Read the sock file data and write the contents to the sock file
             * using proper locking mechanisms. */
            $transaction = socket_recv(
                $this->clientSocket, 
                $sockFileContents, 
                $socketFileSize, 
                MSG_WAITALL
            );
            
            if ($transaction) {
                $this->writeSockFile($sockFileContents);                
            } else {
                throw new RuntimeException(
                    "There was an error receiving the sock file."
                );
            }

            /** Get the query headers. If the server command is "stop" it 
             * throws a RuntimeException and the function returns false
             * which in turn breaks the infinite loop of the spawned process.
             **/
            $queryHeaders = [];

            try {
                $queryHeaders = $this->getQueryHeaders();
            } catch(RuntimeException $e) {
                if ($e->getCode() === ErrorCodes::ILLEGAL_SERVER_COMMAND->value) {
                    echo $e->getMessage() ."\nNot waiting for connections.\n";
                    return false;
                }
            }

            /** Create a lock file informing the parent process that query 
             * processing is taking place. Check to make sure that the lock 
             * file has been successfully created otherwise throw an 
             * exception.  
            */
            $lockFile = fopen($this->lockFilePath, 'w');

            if (!$lockFile)
                throw new RuntimeException(
                    "An error occurred while attempting to create the lock file."
                );

            /** The query header is an associative array comprised of the 
             * following key value pairs :
             * 
             * timestamp : date, 
             * clientId : int, 
             * requestId : int, 
             * hashAlgorithm : string,
             * executionOrder : int, 
             * queryId : int, 
             * queryBody : string, 
             * commandHash : string
            */
            foreach ($queryHeaders as $queryHeader) {            
                if (empty($queryHeader)) 
                    throw new RuntimeException(
                        "Query header may not be empty."
                    );

                extract($queryHeader);

                $hashInput = $timestamp . $clientId . $requestId . 
                    $executionOrder . $queryId . $queryBody;

                /** Computed hash. */
                $testHash = hash($hashAlgorithm, $hashInput);

                /** Run the query. */        
                if ($commandHash === $testHash)
                    $queryOutput = $queryProcessor($queryBody);
                else 
                    throw new RuntimeException(
                        "The computed hash is not equal to the command hash."
                    );

                /** Log the query using $queryLogger. */
                $queryLogger->log($queryHeader);
            }

            /** Delete the lock file after query processing is complete. */
            $lockFileStatus = unlink($this->lockFilePath);

            /** Throw an exception if a file operation error has occurred. */
            if (!$lockFileStatus)
                throw new RuntimeException(
                    "An error occurred while attempting to delete the lock " .
                    "file after queries have been processed."
                );

            /** Continue waiting for connections. */    
            return true;
        }

        /** Three processes are involved in the server operations first is the 
         * primary process that waits for the secondary child process to finish
         * which is contingent on an external signal received in the form of a
         * file based command parsed inside an infinite loop that runs in the 
         * process spawned by the secondary process. The spawned process waits 
         * for incoming connections and reads the data received from a 
         * connected client socket. The sock file is parsed using the received
         * data. The callbacks to process the queries and log the results are
         * passed in as the arguments.
         * 
         * @param callable $queryProcessor A callback that processes the query
         * headers passed to it.
         * @param callable $queryLogger Logs the results of the processed
         * queries.
         * @return ?bool : Returns true if the server has finished executing
         * successfully.
         */
        public function execute(
            callable $queryProcessor, 
            callable $queryLogger) : ?bool {
            // Ensure PCNTL is available
            if (!function_exists('pcntl_fork')) 
                throw new RuntimeException(
                    "PCNTL functions are not available on this PHP installation."
                );
            
            // Fork to get the secondary process running
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException("Primary process fork failed");
            /** The primary process waits for the secondary process to finish. */
            } else if ($pid) {
                echo "QueryServer running as child process with PID: ".$pid."\n";                
                pcntl_wait($status);
                return $pid;
            } else {                
                /** The secondary process forks to monitor the sock file for
                 * control input. 
                */ 
                $ppid = pcntl_fork();

                if ($ppid === -1) {
                    throw new RuntimeException(
                        "Secondary process fork failed."
                    );

                /** The secondary process monitors the sock file and takes 
                 * action depending on the file contents. */
                } else if($ppid) {                                    
                    
                    echo "Secondary process monitoring the sock file.\n";                   
                    
                        /** parseSockFile acquires a shared lock on the sock 
                         * file for reading. */
                        while (true) {

                            $sockFileObject = $this->parseSockFile();
                                                                          
                            $command = $sockFileObject['command'];                            

                            /** Break out of the infinite loop if a "stop" 
                             * command is in the sock file and if there is no 
                             * lock file in place otherwise wait for the query
                             * processing to complete. */
                            if ($command === "stop")           
                                while (!file_exists($this->lockFilePath))
                                    break;

                            /** Wait for a second to read the contents of the 
                             * sock file. */    
                            sleep(2);                            
                        } 
                /** The spawned process calls the blocking accept function and 
                * processes requests as long as it is kept alive by other
                * processes.                  
                */
                } else {                    
                    /** Accept incoming connections until processQueries 
                     * returns false.
                    */
                    while(
                        $this->processQueries(
                            $queryProcessor, 
                            $queryLogger
                    ));
                }
            }            
            /** Server execution completed successfully. */
            return true;
        }
    }
        
    
    