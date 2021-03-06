<?php

/* 
 * Copyright (C) 2014 Richard Lobb
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once('application/libraries/REST_Controller.php');
require_once('application/libraries/LanguageTask.php');

define('MAX_READ', 4096);  // Max bytes to read in popen
define ('MIN_FILE_IDENTIFIER_SIZE', 8);



class Restapi extends REST_Controller {
    
    protected $languages = array();
    protected $file_cache_base = NULL;
    
    // Constructor loads the available languages from the libraries directory.
    // [But to handle CORS (Cross Origin Resource Sharing) it first issues
    // the access-control headers, and then quits if it's an OPTIONS request,
    // which is the "pre-flight" browser generated request to check access.]
    // See http://stackoverflow.com/questions/15602099/http-options-error-in-phil-sturgeons-codeigniter-restserver-and-backbone-js
    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, HEAD, DELETE");
        $method = $_SERVER['REQUEST_METHOD'];
        if($method == "OPTIONS") {
            die();
        }
        parent::__construct();
        $this->file_cache_base = FCPATH . '/files/';
        
        $library_files = scandir('application/libraries');
        foreach ($library_files as $file) {
            $end = '_task.php';
            $pos = strpos($file, $end);
            if ($pos == strlen($file) - strlen($end)) {
                $lang = substr($file, 0, $pos);
                require_once("application/libraries/$file");
                $class = $lang . '_Task';
                $version = $class::getVersion();
                $this->languages[$lang] = $version;
            }
        }
        
        if ($this->config->item('rest_enable_limits')) {
            $this->load->config('per_method_limits');
            $limits = $this->config->item('per_method_limits');
            foreach ($limits as $method=>$limit) {
                $this->methods[$method]['limit'] = $limit;
            }
        }
    }
    
    
    protected function log($type, $message) {
        // Call log_message with the same parameters, but prefix the message
        // by *jobe* for easy identification.
        log_message($type, '*jobe* ' . $message);
    }
    
    
    protected function error($message, $httpCode=400) {
        // Generate the http response containing the given message with the given
        // HTTP response code. Log the error first.
        $this->log('error', $message);
        $this->response($message, $httpCode);
    }
    
    
    public function index_get() {
        $this->response('Please access this API via the runs, runresults, files or languages collections');
    }
    
    // ****************************
    //         FILES
    // ****************************

    // Put (i.e. create or update) a file
    public function files_put($fileId=FALSE) {
        if ($fileId === FALSE) {
            $this->error('No file id in URL');
        }
        $contentsb64 = $this->put('file_contents', FALSE);
        if ($contentsb64 === FALSE) {
            $this->error('put: missing file_contents parameter');
        }

        $contents = base64_decode($contentsb64, TRUE);
        if ($contents === FALSE) {
            $this->error("put: contents of file $fileId are not valid base-64");
        }
        $destPath = $this->file_cache_base . $fileId;

        if (file_put_contents($destPath, $contents) === FALSE) {
            $this->error("put: failed to write file $destPath to cache", 500);
        }
        $len = strlen($contents);
        $this->log('debug', "Put file $fileId, size $len");
        $this->response(NULL, 204);
    }
    
    
    // Check file
    public function files_head($fileId) {
        if (!$fileId) {
            $this->error('head: missing file ID parameter in URL');
        } else if (file_exists($this->file_cache_base .$fileId)) {
            $this->log('debug', "head: file $fileId exists");
            $this->response(NULL, 204);
        } else {
            $this->log('debug', "head: file $fileId not found");
            $this->response(NULL, 404);
        }
    }
    
    // Post file
    public function files_post() {
        $this->error('file_post: not implemented on this server', 403);
    }
 
    // ****************************
    //        RUNS
    // ****************************
    
    public function runs_get() {
        $id = $this->get('runId');
        $this->error('runs_get: no such run or run result discarded', 200);
    }
    
    
    public function runs_post() {
        if (!$run = $this->post('run_spec', FALSE)) {
            $this->error('runs_post: missing or invalid run_spec parameter', 400);
        } elseif (!is_array($run) || !isset($run['sourcecode']) ||
                    !isset($run['language_id'])) {
                $this->error('runs_post: invalid run specification', 400);
        } else {
            // REST_Controller has called to_array on the JSON decoded
            // object, so we must first turn it back into an object
            $run = (object) $run;
            
            // Now we can process the run request
            
            if (isset($run->file_list)) {
                $files = $run->file_list;
                foreach ($files as $file) {
                    if (!$this->is_valid_filespec($file)) {
                        $this->error("runs_post: invalid file specifier: " . print_r($file, TRUE));
                    }
                }
            } else {
                $files = array();
            }

            $language = $run->language_id;
            $input = isset($run->input) ? $run->input : '';
            $params = isset($run->parameters) ? $run->parameters : array();
            if (!array_key_exists($language, $this->languages)) {
                $this->response("Language '$language' is not known", 400);
            } else {
                $reqdTaskClass = ucwords($language) . '_Task';
                if (!isset($run->sourcefilename) || $run->sourcefilename == 'prog.java') {
                    // If no sourcefilename is given or if it's 'prog.java', 
                    // ask the language task to provide a source filename.
                    // The prog.java is a special case (i.e. hack) to support legacy
                    // CodeRunner versions that left it to Jobe to come up with
                    // a name (and in Java it matters).
                    $run->sourcefilename = ''; 
                }
                $this->task = new $reqdTaskClass($run->sourcecode,
                        $run->sourcefilename, $input, $params);
                
                // Debugging is set either via a config parameter or, for a
                // specific run, by the run's debug attribute.
                // When debugging, files are not deleted after the run.
                $debug = $this->config->item('debugging') ||
                        (isset($run->debug) && $run->debug);
                $deleteFiles = !$debug;
                if (!$this->task->load_files($files, $this->file_cache_base)) {
                    $this->task->close($deleteFiles);
                    $this->log('debug', 'runs_post: file(s) not found');
                    $this->response('One or more of the specified files is missing/unavailable', 404);
                } else {
                    try {
                        $this->log('debug', "runs_post: compiling job {$this->task->id}");
                        $this->task->compile();
                        if ($this->task->cmpinfo == '') {
                            $this->log('debug', "runs_post: executing job {$this->task->id}");
                            $this->task->execute($debug);
                        }
                    } catch(exception $e) {
                        $this->response("Server exception ($e)", 500);
                    }
                }

                // Delete files unless it's a debug run

                $this->task->close($deleteFiles); 
            }
        }
        
        $this->log('debug', "runs_post: returning 200 OK for task {$this->task->id}");
        $this->response($this->task->resultObject(), 200);

    }
    
    // **********************
    //      RUN_RESULTS
    // **********************
    public function runresults_get()
    {
        $this->error('runresults_get: unimplemented, as all submissions run immediately.', 404);
    }
    
    
    // **********************
    //      LANGUAGES
    // **********************
    public function languages_get()
    {
        $this->log('debug', 'languages_get called');
        $langs = array();
        foreach ($this->languages as $id => $version) {
            $langs[] = array($id, $version);
        }
        $this->response($langs);
    }
    
    // **********************
    // Support functions
    // **********************
    private function is_valid_filespec($file) {
        return (count($file) == 2 || count($file) == 3) &&
             is_string($file[0]) &&
             is_string($file[1]) &&             
             strlen($file[0]) >= MIN_FILE_IDENTIFIER_SIZE &&
             ctype_alnum($file[0]) &&
             strlen($file[1]) > 0 &&
             ctype_alnum(str_replace(array('-', '_', '.'), '', $file[1]));
    }    

}