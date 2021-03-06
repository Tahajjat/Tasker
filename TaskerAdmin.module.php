<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Tasker admin module
 * 
 * Provides management interfaces (HTML and REST API) for Tasker.
 * 
 * Copyright 2017 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class TaskerAdmin extends Process implements Module {
  // the base URL of the module's admin page
  public $adminUrl;
  // human-readable task descriptions
  public $stateInfo = array(
    Tasker::taskUnknown => 'unknown',
    Tasker::taskActive => 'active',
    Tasker::taskWaiting => 'waiting',
    Tasker::taskSuspended => 'suspended',
    Tasker::taskFinished => 'finished',
    Tasker::taskKilled => 'killed',
    Tasker::taskFailed => 'failed',
  );

  /**
   * Called only when this module is installed
   * 
   * Creates the admin page.
   */
  public function ___install() {
    parent::___install(); // parent creates the admin page
  }

  /**
   * Called only when this module is uninstalled
   * 
   * Removes the admin page.
   */
  public function ___uninstall() {
    parent::___uninstall(); // parent deletes the admin page
  }

  /**
   * Initialization
   * 
   * This function attaches a hook for page save and decodes module options.
   */
  public function init() {
    parent::init();
    if (!$this->modules->isInstalled('Tasker')) {
      $this->error('Tasker module is missing.');
    }
    // set admin URL
    $this->adminUrl = wire('config')->urls->admin.'page/tasks/';
  }

/***********************************************************************
 * Process module endpoints
 * 
 * Module routing under <admin>/page/tasks
 *     admin page - loc: /[?id=$taskId&cmd=$command] - execute() - display tasks or execute a command or a task
 *     JSON API   - loc: /api/?id=$taskId&cmd=$command - executeApi() - execute an api call or a task and return a JSON object
 *    
 * More info about routing:
 * https://processwire.com/talk/topic/7832-module-routing-executesomething-versus-this-input-urlsegment1-in-process-modules/
 **********************************************************************/
  /**
   * Execute the main function for the admin menu interface
   * 
   */
  public function execute() {
    list ($command, $taskId, $params) = $this->analyzeRequestURI($_SERVER['REQUEST_URI']);
    if ($command !== false) {
      // the run command will display it's own page
      if ($command == 'run') return $this->runCommand($command, $taskId, $params);
      $out = '<h2>Executing command '.$command.'</h2>';
      $out .= '<p>'.$this->runCommand($command, $taskId, $params).'</p>';
    } else $out = '';

    $out .= '<h2>Task management</h2>';
    $out .= $this->renderTaskList();

    $out .= '<p><a href="'.$this->page->url.'">Refresh this page.</a></p>';
    return $out;
  }


  /**
   * Execute a command
   * 
   * @param $command string command
   * @param $taskId int ID of the task Page
   * @param $params assoc array of query arguments
   * 
   */
  public function runCommand($command, $taskId, $params) {
    $tasker = wire('modules')->get('Tasker');
    $task = $tasker->getTaskById($taskId);
    if ($task instanceof NullPage) {
      $this->error('Task not found.');
      return;
    }

    switch ($command) {
      case 'start':   // start the task (will be run later)
        return ($tasker->activateTask($task) ? 'Starting task ' : 'Failed to start ').$task->title;
      case 'suspend': // suspend the task but keep progress
        return ($tasker->stopTask($task) ? 'Suspending ' : 'Failed to suspend ').$task->title;
      case 'reset':   // suspend the task and reset progress = restart task
        $task->progress = 0;
        return ($tasker->stopTask($task) ? 'Resetting ' : 'Failed to reset ').$task->title;
      case 'kill':    // stop the task
        return ($tasker->stopTask($task, true) ? 'Killed task ' : 'Failed to kill ').$task->title;
      case 'trash':   // delete the task
        return ($tasker->trashTask($task, $params) ? 'Removed task ' : 'Failed to trash ').$task->title;
      default:
        return 'Unknown command: '.$command;
      case 'run':     // execute and monitor the task right now
        break;
    }

    // start the task (set status to Active)
    $tasker->activateTask($task, $params);

    $out = '<h2>Executing and monitoring task: '.$task->title.'</h2>';
    $out .= $this->renderTaskList('id='.$task->id, '', '', $command);

    return $out;
    // the task will be executed by Javascript functions
  }


  /**
   * Public admin API functions over HTTP (/api)
   * URI structure: .... api/?id=taskId&cmd=command[&arguments]
   */
  public function executeApi() {
    // response object (will be encoded in JSON form)
    $ret = array(
      'taskid' => 0, // text info about the task
      'taskinfo' => '', // text info about the task
      'task_state' => 0, // task numberic state
      'task_state_info' => '', // text info about the task's state
      'status'=> false, // return status
      'result' => '', // result from the task
      'progress' => 0, // percent completed
      'running' => 0, // is the task running?
      'log' => '', // log messages
      'debug' => $this->config->debug, // is debug mode active?
      );

    list ($command, $taskId, $params) = $this->analyzeRequestURI($_SERVER['REQUEST_URI']);
    if (!$command || !$taskId) {
      $ret['result'] = 'Invalid API request: '.$_SERVER['REQUEST_URI'];
      $ret['log'] = $this->getNotices();
      echo json_encode($ret);
      exit;
    }

    // turn off debugging since this is only for executing jobs via javascript
    $this->config->debug = false;

    $tasker = wire('modules')->get('Tasker');
    $task = $tasker->getTaskById($taskId);
    if ($task instanceof NullPage) {
      $ret['result'] = 'No matching task found.';
      $ret['log'] = $this->getNotices();
      echo json_encode($ret);
      exit;
    }

    // report back some info after before we start the command
    $ret['taskinfo'] = $task->title;
    $ret['taskid'] = $task->id;

    switch ($command) {
      case 'status':  // return the task's status
        $ret['status'] = true;
        $ret['result'] = $task->title;
        break;
      case 'restart': // reset progress then start the task
        $task->progress = 0;
      case 'start':   // start/continue the task
        $ret['status'] = true;
        $ret['result'] = $tasker->activateTask($task);
        $ret['running'] = $task->task_running;
        break;
      case 'run':     // execute the task
      case 'exec':    // execute the task
        if (false===($res = $tasker->executeTask($task, $params))) {
          $ret['result'] = 'Task execution failed.';
        } else {
          $ret['status'] = true;
          $ret['result'] = $res;
        }
        break;
      case 'reset':   // stop the task and reset progress
        $task->progress = 0;
      case 'suspend': // stop the task but keep progress
        $ret['status'] = true;
        $tasker->stopTask($task);
        break;
      case 'kill':    // stop the task
        $ret['status'] = true;
        $tasker->stopTask($task, true);
        break;
      case 'trash':   // delete the task
        $ret['status'] = true;
        $tasker->trashTask($task, $params);
        break;
      default:
        $ret['status'] = false;
        $ret['result'] = 'Unknown command.';
        echo json_encode($ret);
        exit;
    }

    // report back some info after the command has finished
    $ret['progress'] = $task->progress;
    $ret['running'] = $task->task_running;
    $ret['task_state'] = $task->task_state;
    $ret['task_state_info'] = $this->stateInfo[$task->task_state];
    $ret['log'] .= "\n<ul class=\"NoticeMessages\">\n";
    foreach (explode("\n", $task->log_messages) as $msg) {
      $ret['log'] .= "  <li>$msg</li>\n";
    }
    $ret['log'] .= "</ul>\n";

    echo json_encode($ret);
    exit; // don't output anything else
  }


/***********************************************************************
 * RENDERING FUNCTIONS
 **********************************************************************/
  /**
   * Render a list of tasks
   * 
   * @param $selector optional pattern to select tasks (the template pattern will be added to it)
   * @param $liClass optional attributes for <li> tags. If null <li> is omitted.
   * @param $aClass optional attributes for <a> tags. If null <a> is omitted.
   * @param $jsCommand optional command that the Javascript functions will execute over the Tasker API
   * @returns html string to output, '' if no tasks have been found, false on error
   */
  public function renderTaskList($selector='sort=task_state', $liClass='', $aClass='', $jsCommand='status') {
    $tasker = wire('modules')->get('Tasker');
    $tasks = $tasker->getTasks($selector);
    if (!count($tasks)) return '<p>No tasks found.</p>';

    // setting up a div field anchor for javascript
    // apiurl points to this module's api endpoint
    // timeout specifies the timeout in ms after which the JS routine will signal an error
    $out = '
      <div id="tasker" adminUrl="'.$this->adminUrl.'api/"
           timeout="'.(1000*intval(ini_get('max_execution_time')))."\">\n";

    // print out info about tasks and commands
    foreach ($tasks as $task) {
      // default html elements for javascript functions for individual task entries
      $jsTaskInfo = '';
      $jsProgressbar = '';
      $taskState = $this->stateInfo[$task->task_state];
      // display info based on the task's state
      switch($task->task_state) {
      case Tasker::taskWaiting:
        $icon = 'fa-clock-o';
        $actions = array('start' => 'Start', 'run' => 'Start & monitor', 'reset' => 'Reset', 'trash' => 'Trash');
        break;
      case Tasker::taskActive:
        $icon = 'fa-rocket';
        if ($task->task_running) {
          $taskState = 'running';
          // for running tasks
          // - instruct the Javascript code to query task status every 10 seconds
          $jsTaskInfo = '<div class="tasker-task" taskId="'.$task->id.'" command="status" repeatTime="10">';
          $actions = array('run' => 'Monitor execution', 'suspend' => 'Suspend', 'reset' => 'Stop & reset', 'kill' => 'Kill');
        } else {
          // for active tasks
          // - instruct the Javascript code to run the task and query status
          $jsTaskInfo = '<div class="tasker-task" taskId="'.$task->id.'" command="'.$jsCommand.'" repeatTime="10">';
          $actions = array('run' => 'Run & Monitor', 'suspend' => 'Suspend', 'reset' => 'Stop & reset', 'kill' => 'Kill');
        }
        // - and display a progress bar
        $jsProgressbar .= '<div><div class="progress-label">Enable Javascript to monitor task progresss.</div></div></div>';
        break;
      case Tasker::taskSuspended:
        $icon = 'fa-hourglass';
        //$icon = 'fa-spinner';
        $actions = array('start' => 'Continue', 'run' => 'Continue & monitor', 'reset' => 'Reset', 'trash' => 'Trash');
        break;
      case Tasker::taskFinished:
        $icon = 'fa-check';
        $actions = array('start' => 'Restart', 'run' => 'Restart & monitor', 'trash' => 'Trash');
        break;
      case Tasker::taskKilled:
        $icon = 'fa-hand-stop-o';
        $actions = array('start' => 'Restart', 'run' => 'Restart & monitor', 'trash' => 'Trash');
        break;
      case Tasker::taskFailed:
        $icon = 'fa-warning';
        $actions = array('start' => 'Restart', 'run' => 'Restart & monitor', 'reset' => 'Reset', 'trash' => 'Trash');
        break;
      default:
        $icon = 'fa-question';
        $actions = $editActions = array();
      }

      $out .= $jsTaskInfo.'
          <i class="fa '.$icon.'">'.$taskState.'</i><i class="fa fa-angle-right"></i>
          <span class="label_title" style="display: inline !important;">'.$task->title.'</span>
          <ul class="actions" style="display: inline !important;">
            ';

      foreach ($actions as $cmd => $title) {
        if ($jsCommand == $cmd) continue; // we're already executing the command, skip its menu element
        $out .= '<li style="display: inline !important;"'.$liClass.'><a href="'.$this->adminUrl.'?id='.$task->id.'&cmd='.$cmd.'"'.$aClass.'>'.$title."</a></li>\n";
      }

      if ($task->editable()) {
        $out .= '<li style="display: inline !important;"'.$liClass.'><a href="'.$task->editUrl().'"'.$aClass.'>Edit</a></li>';
      }

      $out .= "</ul><br />\n{$jsProgressbar}\n";
    } // foreach tasks

    $out .= "</div>\n"; // end Tasker div

    if ($jsCommand == 'run') { // show log messages if we're executing  a task
      $out .= "<div class='log NoticeMessages'></div>\n";
    }

    return $out;
  }


/***********************************************************************
 * LOGGING AND UTILITY METHODS
 **********************************************************************/

  /**
   * Decompose an URI request
   * 
   * TODO: replace taskid/?x=command with command/?id=taskId
   * 
   * @param $request URI
   */
  public function analyzeRequestURI($request) {
    // match the module base URL / command / taskId ? arguments
    $uriparts = parse_url($request);
    $taskId = $command = false;
    $params = array();
    // TODO url segments?
    if (isset($uriparts['query'])) {
      parse_str($uriparts['query'], $query);
      foreach ($query as $key => $value) {
        if ($key == 'id') $taskId = $value;
        elseif ($key == 'cmd') $command = $value;
        else $params[$key] = urldecode($value);
      }
    }

    //$this->message("Command {$command} task {$taskId} with params ".print_r($params, true), Notice::debug);

    return array($command, $taskId, $params);
  }


  /**
   * Get notices
   * 
   * @return HTML-encoded list of system notices
   */
  public function getNotices() {
    $ret = '<ul class="NoticeMessages">';
    foreach(wire('notices') as $notice) {
      $class = $notice->className();
      $text = wire('sanitizer')->entities($notice->text);
      // $ret .= '<li class="'.$class.">$text</li>\n";
      $ret .= "<li>$text</li>\n";
    }
    $ret .= '</ul>';
    return $ret;
  }

}
