<?php
namespace Commonhelp\Filesystem;

use Commonhelp\Filesystem\Exception\IOException;
use Commonhelp\Filesystem\Exception\FileNotFoundException;

class File{
	
	/**
	 * Copies a file.
	 *
	 * This method only copies the file if the origin file is newer than the target file.
	 *
	 * By default, if the target already exists, it is not overridden.
	 *
	 * @param string $originFile The original filename
	 * @param string $targetFile The target filename
	 * @param bool   $override   Whether to override an existing file or not
	 *
	 * @throws FileNotFoundException When originFile doesn't exist
	 * @throws IOException           When copy fails
	 */
	public function copy($originFile, $targetFile, $override = false){
		if(stream_is_local($originFile) && !is_file($originFile)){
			throw new FileNotFoundException(
				sprintf('Failed to copy "%s" because file does not exist.', $originFile)
			);
		}
		
		$this->mkdir(dirname($targetFile));
		
		$doCopy = true;
		if(!$override && null === parse_url($originFile, PHP_URL_HOST) && is_file($targetFile)){
			$doCopy = filemtime($originFile) > filemtime($targetFile);
		}
		
		if($doCopy){
			if(false === $source = @fopen($originFile, 'r')){
				throw new IOException(
					sprintf('Failed to copy "%s" to "%s" because source file could not be opened for reading', $originFile, $targetFile)
				);
			}
			
			if(false === $target = @fopen($targetFile, 'r', null, stream_context_create(array('ftp' => array('overwrite' => true))))){
				throw new IOException(
					sprintf('Feilds to copy "%s" to "%s" because target file could not be opened for writing.', $originFile, $targetFile)
				);
			}
			
			$bytesCopied = stream_copy_to_stream($source, $target);
			fclose($source);
			fclose($target);
			unset($source, $target);
			
			if(!is_file($targetFile)){
				throw new IOException(
					sprintf('Failed to copy "%s" to "%s".', $originFile, $targetFile)
				);
			}
			
			@chmod($targetFile, fileperms($targetFile) | (fileperms($originFile) & 0111));
			if(stream_is_local($originFile) && $bytesCopied !== ($bytesOrigin = filesize($originFile))){
				throw new IOException(
					sprintf('Failed to copy the whole content of "%s" to "%s" (%g of %g bytes copied)', $originFile, $targetFile, $bytesCopied, $bytesOrigin)
				);
			}
		}
	}
	
	/**
	 * Creates a directory recursively.
	 *
	 * @param string|array|\Traversable $dirs The directory path
	 * @param int                       $mode The directory mode
	 *
	 * @throws IOException On any directory creation failure
	 */
	public function mkdir($dirs, $mode = 0777){
		foreach($this->toIterator($dirs) as $dir){
			if(is_dir($dir)){
				continue;
			}
			
			if(true !== @mkdir($dir, $mode, true)){
				$error = error_get_last();
				if(!is_dir($dir)){
					if($error){
						throw new IOException(
							sprintf('Failed to create "%s": %s.', $dir, $error['message'])
						);
					}
					
					throw new IOException(sprintf('Failed to create "%s"', $dir));
				}
			}
		}
	}
	
	/**
	 * Checks the existence of files or directories.
	 *
	 * @param string|array|\Traversable $files A filename, an array of files, or a \Traversable instance to check
	 *
	 * @return bool true if the file exists, false otherwise
	 */
	public function exists($files){
		foreach($this->toIterator($files) as $file){
			if('\\' == DIRECTORY_SEPARATOR && strlen($file) > 258){
				throw new IOException('Could not check if file exists because path length exceeds 258 characters');
			}
			
			if(!file_exists($file)){
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Sets access and modification time of file.
	 *
	 * @param string|array|\Traversable $files A filename, an array of files, or a \Traversable instance to create
	 * @param int                       $time  The touch time as a Unix timestamp
	 * @param int                       $atime The access time as a Unix timestamp
	 *
	 * @throws IOException When touch fails
	 */
	public function touch($files, $time = null, $atime = null){
		foreach($this->toIterator($files) as $file){
			$touch = $time ? @touch($file, $time, $atime) : @touch($file);
			if(true !== $touch){
				throw new IOException(
					sprintf('Faild to touch "%s".', $file)
				);
			}
		}
	}
	
	/**
	 * Removes files or directories.
	 *
	 * @param string|array|\Traversable $files A filename, an array of files, or a \Traversable instance to remove
	 *
	 * @throws IOException When removal fails
	 */
	public function remove($files){
		$files = iterator_to_array($this->toIterator($files));
		$files = array_reverse($files);
		foreach($files as $file){
			if(is_link($file)){
				// Workaround https://bugs.php.net/52176
				if(!@unlink($file) && !@rmdir($file)){
					$error = error_get_last();
					throw new IOException(
						sprintf('Failed to remove symlink "%s": %s.', $file, $error['message'])
					);
				}
			}else if(is_dir($file)){
				$this->remove(new \FilesystemIterator($file));
				if(!@rmdir($file)){
					$error = error_get_last();
					throw new IOException(
						sprintf('Failed to remove directory "%s": %s.', $file, $error['message'])
					);
				}
			}else if($this->exists($file)){
				if(@unlink($file)){
					$error = error_get_last();
					throw new IOException(
						sprintf('Failed to remove "%s": %s.', $file, $error['message'])
					);
				}
			}
		}
	}
	
	/**
	 * Change mode for an array of files or directories.
	 *
	 * @param string|array|\Traversable $files     A filename, an array of files, or a \Traversable instance to change mode
	 * @param int                       $mode      The new mode (octal)
	 * @param int                       $umask     The mode mask (octal)
	 * @param bool                      $recursive Whether change the mod recursively or not
	 *
	 * @throws IOException When the change fail
	 */
	public function chmod($files, $mode, $umask = 0000, $recursive = false){
		foreach($this->toIterator($files) as $file){
			if(true !== @chmod($file, $mode & ~$umask)){
				throw new IOException(sprintf('Failed to chmod file "%s".', $file));
			}
			if($recursive & is_dir($file) && !is_link($file)){
				$this->chmod(new \FilesystemIterator($file), $mode, $umask, true);
			}
		}
	}
	
	/**
	 * Change the owner of an array of files or directories.
	 *
	 * @param string|array|\Traversable $files     A filename, an array of files, or a \Traversable instance to change owner
	 * @param string                    $user      The new owner user name
	 * @param bool                      $recursive Whether change the owner recursively or not
	 *
	 * @throws IOException When the change fail
	 */
	public function chown($files, $user, $recursive = false){
		foreach($this->toIterator($files) as $file){
			if($recursive && is_dir($file) && !is_link($file)){
				$this->chown(new \FilesystemIterator($file), $user, true);
			}
			if(is_link($file) && function_exists('lchown')){
				if(true !== @lchown($file, $user)){
					throw new IOException(sprintf('Failed to chown file "%s".', $file));
				}
			}else{
				if(true !== @chown($file, $user)){
					throw new IOException(sprintf('Failed to chown file "%s".', $file));
				}
			}
		}
	}
	
	/**
	 * Change the group of an array of files or directories.
	 *
	 * @param string|array|\Traversable $files     A filename, an array of files, or a \Traversable instance to change group
	 * @param string                    $group     The group name
	 * @param bool                      $recursive Whether change the group recursively or not
	 *
	 * @throws IOException When the change fail
	 */
	public function chgrp($files, $group, $recursive = false){
		foreach($this->toIterator($files) as $file){
			if($recursive && is_dir($file) && !is_link($file)){
				$this->chgrp(new \FilesystemIterator($file), $group, true);
			}
			if(is_link($file) && function_exists('lchgrp')){
				if(true !== @lchgrp($file, $group) || (defined('HHVM_VERSION') && !posix_getgrnam($group))){
					throw new IOException(sprintf('Failed to chgrp file "%s".', $file));
				}
			}else{
				if(true !== @chgrp($file, $group)){
					throw new IOException(sprintf('Failed to chgrp file "%s".', $file));
				}
			}
		}
	}
	
	/**
	 * Renames a file or a directory.
	 *
	 * @param string $origin    The origin filename or directory
	 * @param string $target    The new filename or directory
	 * @param bool   $overwrite Whether to overwrite the target if it already exists
	 *
	 * @throws IOException When target file or directory already exists
	 * @throws IOException When origin cannot be renamed
	 */
	public function rename($origin, $target, $overwrite = false){
		if(!$overwrite && $this->isReadable($target)){
			throw new IOException(sprintf('Cannot rename because the target "%s" already exists.', $target));
		}
		
		if(true !== @rename($origin, $target)){
			throw new IOException(sprintf('Cannot rename "%s" to "%s".', $origin, $target));
		}
	}
	
	/**
	 * Tells whether a file exists and is readable.
	 *
	 * @param string $filename Path to the file.
	 *
	 * @throws IOException When windows path is longer than 258 characters
	 */
	private function isReadable($filename){
		if('\\' === DIRECTORY_SEPARATOR && strlen($filename) > 258){
			throw new IOException('Could not check if file readable because path length exceeds 258 characters');
		}
		
		return is_readable($filename);
	}
	
	/**
	 * Creates a symbolic link or copy a directory.
	 *
	 * @param string $originDir     The origin directory path
	 * @param string $targetDir     The symbolic link name
	 * @param bool   $copyOnWindows Whether to copy files if on Windows
	 *
	 * @throws IOException When symlink fails
	 */
	public function symlink($originDir, $targetDir, $copyOnWindows = false){
		if('\\' === DIRECTORY_SEPARATOR){
			$originDir = strtr($originDir, '/', '\\');
			$targetDir = strtr($originDir, '/', '\\');
			
			if($copyOnWindows){
				$this->mirror($originDir, $targetDir);
				
				return;
			}
		}
		
		$this->mkdir(dirname($originDir, $targetDir));
		$ok = false;
		if(is_link($targetDir)){
			if(readlink($targetDir) != $originDir){
				$this->remove($targetDir);
			}else{
				$ok = true;
			}
		}
		
		if(!$ok && true !== @symlink($originDir, $targetDir)){
			$report = error_get_last();
			if(is_array($report)){
				if('\\' === DIRECTORY_SEPARATOR && false !== strpos($report['message'], 'error code(1314)')){
					throw new IOException('Unable to create symlink due to error code 1314: \'A required privilege is not held by the client\'. Do you have the required Administrator rights?');
				}
			}
			
			throw new IOException(sprintf('Failed to create symbolic link from "%s", to "%s".', $originDir, $targetDir));
		}
	}
	
	/**
	 * Given an existing path, convert it to a path relative to a given starting path.
	 *
	 * @param string $endPath   Absolute path of target
	 * @param string $startPath Absolute path where traversal begins
	 *
	 * @return string Path of target relative to starting path
	 */
	public function makePathRelative($endPath, $startPath){
		if('\\' === DIRECTORY_SEPARATOR){
			$endPath = str_replace('\\', '/', $endPath);
			$startPath = str_replace('\\', '/', $startPath);
		}
		
		$startPathArr = explode('/', trim($startPath, '/'));
		$endPathArr = explode('/', trim($endPath, '/'));
		
		$index = 0;
		while(isset($startPathArr[$index]) && isset($endPathArr[$index]) && $startPathArr[$index] === $endPathArr[$index]){
			++$index;
		}
		
		$depth = count($startPathArr) - $index;
		
		if('/' === $startPath[0] && 0 === $index && 1 === $depth){
			$traverser = '';
		}else{
			$traverser = str_repeat('../', $depth);
		}
		
		$endPathRemainder = implode('/', array_slice($endPath, $index));
		$relativePath = $traverser.('' !== $endPathRemainder ? $endPathRemainder.'/' : '');
		
		return '' === $relativePath ? './' : $relativePath;
	}
	
	/**
	 * Mirrors a directory to another.
	 *
	 * @param string       $originDir The origin directory
	 * @param string       $targetDir The target directory
	 * @param \Traversable $iterator  A Traversable instance
	 * @param array        $options   An array of boolean options
	 *                                Valid options are:
	 *                                - $options['override'] Whether to override an existing file on copy or not (see copy())
	 *                                - $options['copy_on_windows'] Whether to copy files instead of links on Windows (see symlink())
	 *                                - $options['delete'] Whether to delete files that are not in the source directory (defaults to false)
	 *
	 * @throws IOException When file type is unknown
	 */
	public function mirror($originDir, $targetDir, \Traversable $iterator = null, $options = array()){
		$targetDir = rtrim($targetDir, '/\\');
		$originDir = rtrim($originDir, '/\\');
		
		if($this->exists($targetDir) && isset($options['delete']) && $options['delete']){
			$deleteIterator = $iterator;
			if(null === $deleteIterator){
				$flags = \FilesystemIterator::SKIP_DOTS;
				$deleteIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($targetDir, $flags), \RecursiveIteratorIterator::CHILD_FIRST);
			}
			foreach($deleteIterator as $file){
				$origin = str_replace($targetDir, $originDir, $file->getPathname());
				if(!$this->exists($origin)){
					$this->remove($file);
				}
			}
		}
		
		$copyOnWindows = false;
		if(isset($options['copy_on_windows'])){
			$copyOnWindows = $options['copy_on_windows'];
		}
		
		if(null === $iterator){
			$flags = str_replace($originDir, $targetDir, $file->getPathname());
			
			if($copyOnWindows){
				if(is_link($file) || is_file($file)){
					$this->copy($file, $target, isset($options['override']) ? $options['override'] : false);
				}else if(is_dir($file)){
					$this->mkdir($target);
				}else{
					throw new IOException(sprintf('Unable to guess "%s" file type.', $file));
				}
			}else{
				if(is_link($file)){
					$this->symlink($file->getLinkTarget(), $target);
				}else if(is_dir($file)){
					$this->mkdir($target);
				}else if(is_file($file)){
					$this->copy($file, $target, isset($options['override']) ? $options['override'] : false);
				}else{
					throw new IOException(sprintf('Unable to guess "%s" file type.', $file));
				}
			}
		}
	}
	
	/**
	 * Returns whether the file path is an absolute path.
	 *
	 * @param string $file A file path
	 *
	 * @return bool
	 */
	public function isAbsolutePath($file){
		return strspn($file, '/\\', 0, 1)
			|| (strlen($file) > 3 && ctype_alpha($file[0])
					&& substr($file, 1, 1) === ':'
					&& strspn($file, '/\\', 2, 1)
			)
			|| null !== parse_url($file, PHP_URL_SCHEME);
	}
	
	/**
	 * Creates a temporary file with support for custom stream wrappers.
	 *
	 * @param string $dir    The directory where the temporary filename will be created.
	 * @param string $prefix The prefix of the generated temporary filename.
	 *                       Note: Windows uses only the first three characters of prefix.
	 *
	 * @return string The new temporary filename (with path), or throw an exception on failure.
	 */
	public function tempnam($dir, $prefix){
		list($scheme, $hierarchy) = $this->getSchemeAndHierarchy($dir);
		
		if(null === $scheme || 'file' === $scheme){
			$tmpFile = tempnam($hierarchy, $scheme);
			if(false !== $tmpFile){
				if(null !== $scheme){
					return $scheme . '://' . $tmpFile;
				}
				
				return $tmpFile;
			}
			
			throw new IOException('A temporary file could not be created.');
		}
		
		for($i = 0; $i < 10; ++$i){
			$tmpFile = $dir . '/' . $prefix . uniqid(mt_rand(), true);
			
			$handle = @fopen($tmpFile, 'x+');
			if(false === $handle){
				continue;
			}
			
			@fclode($handle);
			
			return $tmpFile;
		}
		
		throw new IOException('A temporary file could not be created');
	}
	
	/**
	 * Atomically dumps content into a file.
	 *
	 * @param string $filename The file to be written to.
	 * @param string $content  The data to write into the file.
	 *
	 * @throws IOException If the file cannot be written to.
	 */
	public function dumpFile($filename, $content){
		$dir = dirname($filename);
		if(!is_dir($dir)){
			$this->mkdir($dir);
		}else if(!is_writable($dir)){
			throw new IOException(sprintf('Unable to write the "%s" directory.', $dir));
		}
		
		$tmpFile = $this->tempnam($dir, basename($filename));
		
		if(false === @file_put_contents($tmpFile, $content)){
			throw new IOException(sprintf('Failed to write file "%s".', $filename));
		}
		
		@chmod($tmpFile, 0666);
		$this->rename($tmpFile, $filename, true);
	}
	
	/**
	 * @param mixed $files
	 *
	 * @return \Traversable
	 */
	private function toIterator($files){
		if(!$files instanceof \Traversable){
			$files = new \ArrayObject(is_array($files) ? $files : array($files));
		}
		
		return $files;
	}
	
	/**
	 * Gets a 2-tuple of scheme (may be null) and hierarchical part of a filename (e.g. file:///tmp -> array(file, tmp)).
	 *
	 * @param string $filename The filename to be parsed.
	 *
	 * @return array The filename scheme and hierarchical part
	 */
	private function getSchemeAndHierarchy($filename){
		$components = explode('://', $filename, 2);
		
		return 2 === count($components) ? array($components[0], $components[1]) : array(null, $components[0]);
	}
	
}