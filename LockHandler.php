<?php
namespace Commonhelp\Filesystem;

use Commonhelp\Filesystem\Exception\IOException;

class LockHandler{
	
	private $file;
	private $handle;
	
	/**
	 * @param string      $name     The lock name
	 * @param string|null $lockPath The directory to store the lock. Default values will use temporary directory
	 *
	 * @throws IOException If the lock directory could not be created or is not writable
	 */
	public function __construct($name, $lockPath = null){
		$lockPath = $lockPath ?: sys_get_temp_dir();
		
		if(!is_dir($lockPath)){
			$fs = new File();
			$fs->mkdir($lockPath);
		}
		
		if(!is_writeable($lockPath)){
			throw new IOException(sprintf('The directory "%s" is not writeable.', $lockPath));
		}
		
		$this->file = sprintf('%s/sf.%s%s.lock', $lockPath, preg_replace('/[^a-z0-9\._-]+/i', '-', $name), hash('sha256', $name));
	}
	
	/**
	 * Lock the resource.
	 *
	 * @param bool $blocking wait until the lock is released
	 *
	 * @return bool Returns true if the lock was acquired, false otherwise
	 *
	 * @throws IOException If the lock file could not be created or opened
	 */
	public function lock($blocking = false){
		if($this->handle){
			return true;
		}
		
		set_error_handler(function() {});
		
		if(!$this->handle = fopen($this->file, 'r')){
			if($this->handle = fopen($this->file, 'x')){
				chmod($this->file, 0444);
			}else if(!$this->handle = fopen($this->file, 'r')){
				usleep(100);
				$this->handle = fopen($this->file, 'r');
			}
		}
		
		restore_error_handler();
		
		if(!$this->handle){
			$error = error_get_last();
			throw new IOException($error['message']);
		}
		
		if(!flock($this->handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))){
			fclose($this->handle);
			$this->handle = null;
			
			return false;
		}
		
		return true;
	}
	

	/**
	 * Release the resource.
	 */
	public function release(){
		if($this->handle){
			flock($this->handle, LOCK_UN | LOCK_NB);
			fclose($this->handle);
			$this->handle = null;
		}
	}
	
}