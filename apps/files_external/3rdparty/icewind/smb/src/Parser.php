<?php
/**
 * Copyright (c) 2014 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Licensed under the MIT license:
 * http://opensource.org/licenses/MIT
 */

namespace Icewind\SMB;

use Icewind\SMB\Exception\AccessDeniedException;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\Exception;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\NotEmptyException;
use Icewind\SMB\Exception\NotFoundException;

class Parser {
	/**
	 * @var string
	 */
	protected $timeZone;

	/**
	 * @param string $timeZone
	 */
	public function __construct($timeZone) {
		$this->timeZone = $timeZone;
	}

	public function checkForError($output, $path) {
		if (count($output) === 0) {
			return true;
		} else {
			if (strpos($output[0], 'does not exist')) {
				throw new NotFoundException($path);
			}
			$parts = explode(' ', $output[0]);
			$error = false;
			foreach ($parts as $part) {
				if (substr($part, 0, 9) === 'NT_STATUS') {
					$error = $part;
				}
			}
			switch ($error) {
				case ErrorCodes::PathNotFound:
				case ErrorCodes::ObjectNotFound:
				case ErrorCodes::NoSuchFile:
					throw new NotFoundException($path);
				case ErrorCodes::NameCollision:
					throw new AlreadyExistsException($path);
				case ErrorCodes::AccessDenied:
					throw new AccessDeniedException($path);
				case ErrorCodes::DirectoryNotEmpty:
					throw new NotEmptyException($path);
				case ErrorCodes::FileIsADirectory:
				case ErrorCodes::NotADirectory:
					throw new InvalidTypeException($path);
				default:
					$message = 'Unknown error (' . $error . ')';
					if ($path) {
						$message .= ' for ' . $path;
					}
					throw new Exception($message);
			}
		}
	}

	public function parseMode($mode) {
		$result = 0;
		$modeStrings = array(
			'R' => FileInfo::MODE_READONLY,
			'H' => FileInfo::MODE_HIDDEN,
			'S' => FileInfo::MODE_SYSTEM,
			'D' => FileInfo::MODE_DIRECTORY,
			'A' => FileInfo::MODE_ARCHIVE,
			'N' => FileInfo::MODE_NORMAL
		);
		foreach ($modeStrings as $char => $val) {
			if (strpos($mode, $char) !== false) {
				$result |= $val;
			}
		}
		return $result;
	}

	public function parseStat($output) {
		$mtime = 0;
		$mode = 0;
		$size = 0;
		foreach ($output as $line) {
			list($name, $value) = explode(':', $line, 2);
			$value = trim($value);
			if ($name === 'write_time') {
				$mtime = strtotime($value);
			} else if ($name === 'attributes') {
				$mode = hexdec(substr($value, 1, -1));
			} else if ($name === 'stream') {
				list(, $size,) = explode(' ', $value);
				$size = intval($size);
			}
		}
		return array(
			'mtime' => $mtime,
			'mode' => $mode,
			'size' => $size
		);
	}

	public function parseDir($output, $basePath) {
		//last line is used space
		array_pop($output);
		$regex = '/^\s*(.*?)\s\s\s\s+(?:([NDHARS]*)\s+)?([0-9]+)\s+(.*)$/';
		//2 spaces, filename, optional type, size, date
		$content = array();
		foreach ($output as $line) {
			if (preg_match($regex, $line, $matches)) {
				list(, $name, $mode, $size, $time) = $matches;
				if ($name !== '.' and $name !== '..') {
					$mode = $this->parseMode($mode);
					$time = strtotime($time . ' ' . $this->timeZone);
					$content[] = new FileInfo($basePath . '/' . $name, $name, $size, $time, $mode);
				}
			}
		}
		return $content;
	}
}
