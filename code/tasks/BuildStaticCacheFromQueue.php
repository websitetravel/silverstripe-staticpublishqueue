<?php
/**
 * This tasks takes care of republishing pages that has been queues in the StaticPagesQueue.
 * 
 * Only one instace of this script can be run at a time. The script will take
 * lock on pidfile at <silverstripe-cache>/pid.buildstaticcachefromqueue.txt.
 *
 * The script has a timer that makes sure the script in daemon mode will run
 * only for 590 sec and terminate. This is to make sure that the script doesn't
 * hog up to much resources and also if we do a deploy or so.
 * 
 * At can be run in browser, from command line as:
 * /dev/tasks/BuildStaticCacheFromQueue
 *
 * Or from cronjobs with a less expressive output as:
 * /dev/tasks/BuildStaticCacheFromQueue verbose=1
 * 
 * Or for running it in the crontab this is the recommended way. Note that the nice command decreases the priority
 * of the queue processing so it doesn't interfere with web-server requests
 *
   * * * * * /bin/nice -n -10 /path/to/site/sapphire/sake dev/tasks/BuildStaticCacheFromQueue daemon=1 verbose=0 >> /tmp/cachebuilder.log
 *
 */
class BuildStaticCacheFromQueue extends BuildTask {

	/**
	 * @var URLArrayObject
	 */
	protected $urlArrayObject;

	private static $dependencies = array(
		'urlArrayObject' =>  '%$URLArrayObject'
	);

	/**
	 * Should be self exploratory. Note: needs to public due to error handling
	 *
	 * @var string
	 */
	public static $current_url = '';

	/**
	 *
	 * @var type
	 */
	protected $title = "Build static cache from queue";

	/**
	 *
	 * @var type
	 */
	protected $description = "Takes urls from the StaticPagesQueue and rebuilds them.";

	/**
	 * Marks if the task should be chatty. Can be used for less outpug when running
	 * cronjobs.
	 *
	 * @var boolean
	 */
	protected $verbose = false;
	
	/**
	 * This tells us if the task is running in a daemon mode.
	 *
	 * @var bool
	 */
	protected $daemon = false;
	
	/**
	 *
	 * @var BuildStaticCacheSummary
	 */
	protected $summaryObject = null;

	public function setUrlArrayObject($o) {
		$this->urlArrayObject = $o;
	}

	public function getUrlArrayObject() {
		return $this->urlArrayObject;
	}

	/**
	 * Starts the republishing of pages in the StaticPagesQueue
	 *
	 * @var $request SS_HTTPRequest
	 */
	public function run($request) {
		if (!defined('SS_SLAVE')) { //don't run any build task if we are the slave server
			if($request->getVar('verbose') === 0) {
				$this->verbose = false;
			} else {    //verbose logging is the default, unless we specify otherwise
				$this->verbose = true;
			}

			// Get lock, based on http://stackoverflow.com/a/24665209/359059
			$lock_file = fopen($this->getPidFilePath(), 'c');
			$got_lock = flock($lock_file, LOCK_EX | LOCK_NB, $wouldblock);
			if ($lock_file === false || (!$got_lock && !$wouldblock)) {
				throw new Exception(
					"Unexpected error opening or locking lock file. Perhaps you " .
					"don't  have permission to write to the lock file or its " .
					"containing directory?"
				);
			} else if (!$got_lock && $wouldblock) {
				if ($this->verbose) {
					$pid = (int)trim(file_get_contents($this->getPidFilePath()));
					echo "Another instance is already running ($pid); terminating.\n";
				}
				return false;
			}

			// Lock acquired; let's write our PID to the lock file for the convenience
			// of humans who may wish to terminate the script.
			ftruncate($lock_file, 0);
			fwrite($lock_file, getmypid() . "\n");

			// Processing starts here
			if($request->getVar('daemon')) {
				$this->daemon = true;
				while($this->buildCache() && $this->hasRunLessThan(590)) {
					usleep(200000); //sleep for 200 ms
					$this->runningTime();
					$this->summaryObject = null;
				}
			} else {
				$this->buildCache();
			}

			// All done; we blank the PID file and explicitly release the lock
			// (although this should be unnecessary) before terminating.
			ftruncate($lock_file, 0);
			flock($lock_file, LOCK_UN);
		} else {
			echo "Server is SS_SLAVE, not running build task (edit the _ss_environment file to make this server the master)";
		}
	}
	
	/**
	 *
	 * @return boolean - if this task was run
	 */
	protected function buildCache() {
		$this->load_error_handlers();
		$published = 0;
		while(self::$current_url = StaticPagesQueue::get_next_url()) {
			$prePublishTime = microtime(true);
			$results = $this->createCachedFiles(array(self::$current_url));
			if($this->verbose) {
				foreach($results as $url => $data) {
					$this->printPublishingMetrics(
						$published++,
						$prePublishTime,
						$url,
						sprintf('HTTP Status: %d', $results[$url]['statuscode'])
					);
				}
			}
			$this->logSummary($published, false, $prePublishTime);
			StaticPagesQueue::delete_by_link(self::$current_url);
		}
		
		if($published) {
			$this->logSummary($published, true, $prePublishTime);
		}
		return true;
	}

	/**
	 * Builds cached files and stale files that is a copy of an existing cached page but with
	 * a notice that the page is stale.
	 *
	 * If a SubsiteID GET parameter is found in the URL, the page is generated into a directory,
	 * regardless if it's from the main site or subsite.
	 *
	 * @param array $URLSegments
	 */
	protected function createCachedFiles(array $URLSegments) {
		$results = array();
		foreach($URLSegments as $index => $url) {
			$obj = $this->getUrlArrayObject()->getObject($url);

			if (!$obj || !$obj->hasExtension('SiteTreeSubsites')) {
				// No metadata available. Pass it straight on.
				$results = singleton("SiteTree")->publishPages(array($url));

			} else {
				Config::inst()->nest();

				// Subsite page requested. Change behaviour to publish into directory.
				Config::inst()->update('FilesystemPublisher', 'domain_based_caching', true);

				// Pop the base-url segment from the url.
				if (strpos($url, '/')===0) $cleanUrl = Director::makeRelative($url);
				else $cleanUrl = Director::makeRelative('/' . $url);

				$subsite = $obj->Subsite();
				if (!$subsite || !$subsite->ID) {
					// Main site page - but publishing into subdirectory.
					$staticBaseUrl = Config::inst()->get('FilesystemPublisher', 'static_base_url');

					// $staticBaseUrl contains the base segment already.
					$results = singleton("SiteTree")->publishPages(
						array($staticBaseUrl . '/' . $cleanUrl)
					);

				} else {
					// Subsite page. Generate all domain variants registered with the subsite.
					Config::inst()->update('FilesystemPublisher', 'static_publisher_theme', $subsite->Theme);

                    $onlyPublishPrimaryDomains = Config::inst()->get('StaticPagesQueue', 'publish_primary_domains_only');

					foreach($subsite->Domains() as $domain) {

                        if ($onlyPublishPrimaryDomains && !$domain->IsPrimary) {
                            continue;
                        }

						Config::inst()->update(
							'FilesystemPublisher',
							'static_base_url',
							'http://'.$domain->Domain . Director::baseURL()
						);

						$result = singleton("SiteTree")->publishPages(
							array('http://'.$domain->Domain . Director::baseURL() . $cleanUrl)
						);
						$results = array_merge($results, $result);
					}
				}

				Config::inst()->unnest();
			}
		}

		// Create or remove stale file
		$newResults = array();
		$publisher = singleton('SiteTree')->getExtensionInstance('FilesystemPublisher');
		if ($publisher) {
			foreach($results as $url => $result) {
				if($result['path']) {
					$filePath = $result['path'];
				} else {
					$pathsArray = $publisher->urlsToPaths(array($url));
					$filePath = $publisher->getDestDir() . '/' . array_shift($pathsArray);
				}
				$staleFilepath = str_replace('.'.pathinfo($filePath, PATHINFO_EXTENSION), '.stale.html', $filePath);

				//keep the stale file is there is an error when generating the file
				if (file_exists($filePath)) {
					$filePathContents = file_get_contents($filePath);
					if (strlen($filePathContents) > 0 &&
						$result['statuscode'] < 400) {
						$staleContent = str_replace('<div id="stale"></div>', $this->getStaleHTML(), $filePathContents);
						file_put_contents($staleFilepath, $staleContent, LOCK_EX);
						chmod($staleFilepath, 0664);
						chmod($filePath, 0664);
						$result['action'] = "update";
					}
				} elseif (strval($result['statuscode']) === '404') {   //file does not exist AND status code is 404
					// For HTTP errors, we DO NOT remove the stale file.
					// The page was correctly published, but there might have been an intermittent error
					// Deleting the original cache file would've already been taken care of by
					// FilesystemPublisher->unpublishPages(), so we only want ot delete the stale page
					// once we have a confirmed 404, or when overwriting it with a published new page
					if (file_exists($staleFilepath)) {
						user_error("Stale file exists on page that returns a 404. Stale file should have been deleted with the unpublish action, or page is erroneously returning a 404. Keeping stale file, but this might be an error: ".$staleFilepath, E_USER_ERROR);
						$result['action'] = "errorStaleFileExistsFor404";
					} else {
						$result['action'] = "unlinkFileDoesNotExist";
					}
				}

				//record the action in the results that return
				$newResults[$url] = $result;
			}
		}

		return $newResults;
	}

	/**
	 *
	 * @return string
	 */
	protected function getStaleHTML() {
		return <<<EOT
<div id="stale" class='page-is-stale'>
	<p>This page is a bit old, but will soon be updated.</p>
</div>
EOT;
	}

	/**
	 * Prints a one row summary of the publishing
	 *
	 * @param int $publishedPages
	 * @param float $prePublishTime
	 * @param String $url
	 * @param String $extra Additional info, like HTTP status codes
	 * @param string $url
	 */
	protected function printPublishingMetrics($publishedPages, $prePublishTime, $url, $extra = null) {
		static $previous_memory_usage = 0;
		if(!$previous_memory_usage) {
			$previous_memory_usage = memory_get_usage(true);
		}
		$memoryDelta = memory_get_usage(true)-$previous_memory_usage;
		$mbDivider=1024*1024;

		$publishTime = microtime(true) - $prePublishTime;
		printf(
			"%s %s %.2fs %.1fmb %.1fmb %s (%s)",
			date("Y-m-d H:i:s"),
			$publishedPages, 
			$publishTime, 
			$memoryDelta/$mbDivider, 
			memory_get_usage()/$mbDivider, 
			$url,
			$extra
		);
		echo $this->nl();
	}

	/**
	 *
	 * @param int $publishedPages - the number of pages published so far
	 */
	protected function logSummary( $publishedPages, $finished, $prePublishTime ) {
		if(!$publishedPages) {
			return;
		}
		if( !$this->summaryObject ) {
			$this->summaryObject = new BuildStaticCacheSummary();
			$this->summaryObject->PID = getmypid();
		}
		$this->summaryObject->Pages = $publishedPages;
		$this->summaryObject->TotalTime = (time() - $prePublishTime);
		$this->summaryObject->AverageTime = sprintf('%.2f',($this->runningTime()/$publishedPages));
		$this->summaryObject->MemoryUsage = sprintf('%.2f',(memory_get_usage(true)/1024/1024));
		$this->summaryObject->Finished = $finished;
		$this->summaryObject->write();

		$this->cleanOldSummaryLog();
	}
	
	/**
	 *
	 * @return type 
	 */
	protected function cleanOldSummaryLog() {
		$oneWeekAgo= date('Y-m-d H:i:s', strtotime('- 1 week'));
		DB::query('DELETE FROM "BuildStaticCacheSummary" WHERE "LastEdited" < \''.$oneWeekAgo.'\';');
	}

	/**
	 * Return an boolean to see if this class has run more than x seconds and
	 * then return true;
	 *
	 * @param int $seconds
	 * @return boolean
	 */
	protected function hasRunLessThan( $seconds ) {
		return $this->runningTime() < $seconds;
	}

	/**
	 * Get the absolute path to the pidfile
	 *
	 * @return string
	 */
	protected function getPidFilePath() {
		return getTempFolder() . DIRECTORY_SEPARATOR . 'pid.'.strtolower(__CLASS__).'.txt';
	}

	/**
	 * Returns the number of seconds since first calling this function
	 *
	 * @return int - seconds
	 */
	protected function runningTime() {
		static $start_time = 0;
		if(!$start_time) {
			$start_time = time();
		}
		return time() - $start_time;
	}
	
	/**
	 * Returns an nl appropiate for CLI or HTML
	 *
	 * @return string
	 */
	private function nl(){
		return (Director::is_cli())?PHP_EOL:'<br>';
	}
	
	private function isDaemon() {
		return $this->daemon;
	}

	/**
	 * Override error_handlers so we can set a page in error state
	 *
	 */
	protected function load_error_handlers() {
		set_error_handler(array(__CLASS__, 'error_handler'), error_reporting());
		set_exception_handler(array(__CLASS__, 'exception_handler'));
	}

	/**
	 * Generic callback to catch standard PHP runtime errors thrown by the interpreter
	 * or manually triggered with the user_error function.
	 * Caution: The error levels default to E_ALL is the site is in dev-mode (set in main.php).
	 *
	 * @ignore
	 * @param int $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param int $errline
	 */
	public static function error_handler($errno, $errstr, $errfile, $errline) {
		switch ($errno) {
			case E_ERROR:
			case E_CORE_ERROR:
			case E_USER_ERROR:
				StaticPagesQueue::has_error(self::$current_url, $errstr);
				Debug::fatalHandler($errno, $errstr, $errfile, $errline, null);
				break;

			case E_WARNING:
			case E_CORE_WARNING:
			case E_USER_WARNING:
				StaticPagesQueue::has_error(self::$current_url, $errstr);
				Debug::warningHandler($errno, $errstr, $errfile, $errline, null);
				break;

			case E_NOTICE:
			case E_USER_NOTICE:
				Debug::noticeHandler($errno, $errstr, $errfile, $errline, null);
				break;
		}
	}

	/**
	 * Generic callback, to catch uncaught exceptions when they bubble up to the top of the call chain.
	 *
	 * @ignore
	 * @param Exception $exception
	 */
	public static function exception_handler($exception) {
		StaticPagesQueue::has_error(self::$current_url);
		$errno = E_USER_ERROR;
		$type = get_class($exception);
		$message = "Uncaught " . $type . ": " . $exception->getMessage();
		$file = $exception->getFile();
		$line = $exception->getLine();
		$context = $exception->getTrace();
		Debug::fatalHandler($errno, $message, $file, $line, $context);
	}

}
