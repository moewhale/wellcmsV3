<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

/**
 * ZipUtility
 *
 * 单文件、零依赖、兼容 PHP 7.2 — PHP 8.x。
 * 先用 ZipArchive，缺失时自动降级到纯 PHP 实现（原 php_zip 逻辑）。
 * 功能：压缩 / 解压、递归创建目录、递归复制 / 删除目录、自动扁平化多余目录层。
 *
 * 用法：
 * $zip = new ZipUtility();
 * $zip->zip('/tmp/demo.zip', '/path/to/dir');
 * $zip->unzip('/tmp/demo.zip', '/path/to/output/');
 */
class ZipUtility
{
	private const MAX_ENTRIES = 10000;
	private const MAX_TOTAL_UNCOMPRESSED = 1073741824;
	private const MAX_FILE_UNCOMPRESSED = 268435456;
	private const MAX_COMPRESSION_RATIO = 200;

	/**
	 * 压缩
	 * @param string|null $zipRootName ZIP 内根目录名（null 则使用 basename($srcPath)）
	 */
	public function zip(string $zipfile, string $srcPath, ?string $zipRootName = null): void
	{
		$zipfile  = strtr($zipfile,  '\\', '/');
		$srcPath  = rtrim(strtr(realpath($srcPath) ?: $srcPath, '\\', '/'), '/');

		is_file($zipfile) && unlink($zipfile);

		$rootName = $zipRootName !== null ? $zipRootName : basename($srcPath);

		if (class_exists('ZipArchive')) {
			$this->zipWithZipArchive($zipfile, $srcPath, $rootName);
		} else {
			$this->zipFallback($zipfile, $srcPath, $rootName);
		}
	}

	/** 解压 */
	public function unzip(string $zipfile, string $destPath, bool $flattenSameName = true): void
	{
		$zipfile  = strtr($zipfile,  '\\', '/');
		$destPath = rtrim(strtr($destPath, '\\', '/'), '/') . '/';

		if (class_exists('ZipArchive')) {
			$this->unzipWithZipArchive($zipfile, $destPath);
		} else {
			$this->unzipFallback($zipfile, $destPath);
		}

		if (!$flattenSameName) return;

		// 自动扁平化同名双层目录
		$last = basename(rtrim($destPath, '/'));
		if (is_dir($destPath . $last)) {
			$tmp = $destPath . '__tmp__' . random_int(100000, 999999) . '/';
			rename(rtrim($destPath, '/'), rtrim($tmp, '/'));
			rename($tmp . $last, rtrim($destPath, '/'));
			$this->rmdirRecursive($tmp);
		}
	}

	/* ========= ZipArchive ========= */

	private function zipWithZipArchive(string $zipfile, string $srcPath, string $zipRootName): void
	{
		$za = new \ZipArchive();
		if ($za->open($zipfile, \ZipArchive::CREATE) !== true) {
			throw new \RuntimeException("Unable to create $zipfile");
		}

		$base = $zipRootName;
		$za->addEmptyDir($base);
		$this->addDirToZip($za, $srcPath, strlen($srcPath), $zipRootName);
		$za->close();
	}

	private function addDirToZip(\ZipArchive $za, string $dir, int $stripLen, string $zipRootName): void
	{
		foreach (glob(rtrim($dir, '/') . '/*') as $item) {
			$local = $zipRootName . substr($item, $stripLen);
			if (is_dir($item)) {
				$za->addEmptyDir($local);
				$this->addDirToZip($za, $item, $stripLen, $zipRootName);
			} else {
				$za->addFile($item, $local);
			}
		}
	}

	private function unzipWithZipArchive(string $zipfile, string $destPath): void
	{
		$za = new \ZipArchive();
		if ($za->open($zipfile) !== true) {
			throw new \RuntimeException("Unable to open $zipfile");
		}
		try {
			if ($za->numFiles > self::MAX_ENTRIES) {
				throw new \RuntimeException('ZIP entry count exceeds the allowed limit');
			}
			$totalSize = 0;
			for ($i = 0; $i < $za->numFiles; $i++) {
				$stat = $za->statIndex($i);
				if (!is_array($stat)) {
					throw new \RuntimeException('Unable to read ZIP entry metadata');
				}
				$name = (string)($stat['name'] ?? '');
				$size = (int)($stat['size'] ?? 0);
				$compressedSize = (int)($stat['comp_size'] ?? 0);
				$this->validateEntry($name, $size, $compressedSize);
				$totalSize += $size;
				if ($totalSize > self::MAX_TOTAL_UNCOMPRESSED) {
					throw new \RuntimeException('ZIP total uncompressed size exceeds the allowed limit');
				}

				$operationsSystem = 0;
				$attributes = 0;
				if ($za->getExternalAttributesIndex($i, $operationsSystem, $attributes)) {
					$mode = ($attributes >> 16) & 0170000;
					if ($mode === 0120000) {
						throw new \RuntimeException('ZIP symbolic links are not allowed');
					}
				}
			}

			$this->ensureDir($destPath);
			if (!$za->extractTo($destPath)) {
				throw new \RuntimeException('ZIP extraction failed');
			}
		} finally {
			$za->close();
		}
	}

	/* ========= 纯 PHP fallback ========= */

	// 内部缓冲
	/** @var array */
	private $ctrlDir  = [];
	/** @var array */
	private $dataSec  = [];
	/** @var int */
	private $oldOffset = 0;

	private function zipFallback(string $zipfile, string $srcPath, string $zipRootName): void
	{
		if (!function_exists('gzcompress')) {
			throw new \RuntimeException('zlib extension required for fallback zip');
		}

		foreach ($this->walkFiles($srcPath) as $file) {
			$content = is_file($file) ? file_get_contents($file) : '';
			$relPath = ltrim(str_replace('\\', '/', substr($file, strlen($srcPath))), '/');
			$name    = $zipRootName . ($relPath !== '' ? '/' . $relPath : '');
			$this->addFileToBuffer($content, $name, is_file($file) ? filemtime($file) : time());
		}
		file_put_contents($zipfile, $this->buildZip());
	}

	private function unzipFallback(string $zipfile, string $destPath): void
	{
		$zip = fopen($zipfile, 'rb');
		if (!$zip) {
			throw new \RuntimeException("Cannot open $zipfile");
		}

		try {
			$cdir = $this->readCentralDirectory($zip, $zipfile);
			if ($cdir['entries'] > self::MAX_ENTRIES) {
				throw new \RuntimeException('ZIP entry count exceeds the allowed limit');
			}
			$pos = $cdir['offset'];
			$totalSize = 0;
			$entries = [];

			for ($i = 0; $i < $cdir['entries']; $i++) {
				if (fseek($zip, $pos) !== 0) {
					throw new \RuntimeException('Invalid ZIP central directory offset');
				}
				$header = $this->centralHeader($zip);
				$pos = ftell($zip);
				$this->validateEntry($header['name'], $header['usize'], $header['csize']);
				if (($header['flag'] & 1) !== 0 || !in_array($header['cmpr'], [0, 8], true)) {
					throw new \RuntimeException('Encrypted or unsupported ZIP entry');
				}
				$mode = ($header['ext'] >> 16) & 0170000;
				if ($mode === 0120000) {
					throw new \RuntimeException('ZIP symbolic links are not allowed');
				}
				$totalSize += $header['usize'];
				if ($totalSize > self::MAX_TOTAL_UNCOMPRESSED) {
					throw new \RuntimeException('ZIP total uncompressed size exceeds the allowed limit');
				}
				$entries[] = $header;
			}

			$this->ensureDir($destPath);
			foreach ($entries as $header) {
				if (fseek($zip, $header['off']) !== 0) {
					throw new \RuntimeException('Invalid ZIP local header offset');
				}
				$local = $this->fileHeader($zip);
				if (!hash_equals($header['name'], $local['name']) || $header['cmpr'] !== $local['cmpr']) {
					throw new \RuntimeException('ZIP central and local headers do not match');
				}
				$this->extractFile($header, $destPath, $zip);
			}
		} finally {
			fclose($zip);
		}
	}

	/* ========= 工具 ========= */

	/** 递归列出文件 & 目录（目录以 / 结尾） */
	private function walkFiles(string $path): \Generator
	{
		$path = rtrim($path, '/') . '/';
		$dir  = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
		$it   = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $item) {
			yield $item->getPathname();
		}
	}

	private function ensureDir(string $dir): void
	{
		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException('Unable to create ZIP extraction directory');
		}
	}

	private function validateEntry(string $name, int $size, int $compressedSize): void
	{
		if ($name === '' || strlen($name) > 4096 || strpos($name, "\0") !== false
			|| strpos($name, '\\') !== false || $name[0] === '/'
			|| preg_match('/^[A-Za-z]:/', $name)) {
			throw new \RuntimeException('Unsafe ZIP entry name');
		}
		foreach (explode('/', rtrim($name, '/')) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new \RuntimeException('ZIP entry path traversal detected');
			}
		}
		if ($size < 0 || $compressedSize < 0 || $size > self::MAX_FILE_UNCOMPRESSED) {
			throw new \RuntimeException('ZIP entry size exceeds the allowed limit');
		}
		if ($size > 0 && ($compressedSize === 0 || $size > $compressedSize * self::MAX_COMPRESSION_RATIO)) {
			throw new \RuntimeException('ZIP compression ratio exceeds the allowed limit');
		}
	}

	private function rmdirRecursive(string $dir): void
	{
		if (!is_dir($dir)) return;
		$it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
		foreach (new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST) as $path) {
			$path->isDir() ? rmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	/* ======== fallback-ZIP 写 ======== */

	private function addFileToBuffer(string $data, string $name, int $time): void
	{
		$d = getdate($time);
		if ($d['year'] < 1980) {
			$d = ['year' => 1980, 'mon' => 1, 'mday' => 1, 'hours' => 0, 'minutes' => 0, 'seconds' => 0] + $d;
		}
		$dos = (($d['year'] - 1980) << 25) | ($d['mon'] << 21) | ($d['mday'] << 16) | ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);

		$crc = crc32($data);
		$z   = gzcompress($data);
		$z   = substr(substr($z, 2), 0, -4);

		// local header
		$fr  = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00";
		$fr .= pack('V', $dos) . pack('VVVvv', $crc, strlen($z), strlen($data), strlen($name), 0);
		$fr .= $name . $z;

		$this->dataSec[] = $fr;
		$offset          = strlen(implode('', $this->dataSec));

		// central directory
		$cd  = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00";
		$cd .= pack('V', $dos)
			. pack('VVVvvvvvVV', $crc, strlen($z), strlen($data), strlen($name), 0, 0, 0, 0, 32, $this->oldOffset)
			. $name;

		$this->oldOffset = $offset;
		$this->ctrlDir[] = $cd;
	}

	private function buildZip(): string
	{
		$data = implode('', $this->dataSec);
		$ctrl = implode('', $this->ctrlDir);
		return $data
			. $ctrl
			. "\x50\x4b\x05\x06\x00\x00\x00\x00"
			. pack('vvVV', count($this->ctrlDir), count($this->ctrlDir), strlen($ctrl), strlen($data))
			. "\x00\x00";
	}

	/* ======== fallback-ZIP 读 ======== */

	/**
	 * @param resource $zip
	 */
	private function readCentralDirectory($zip, string $file): array
	{
		$size = filesize($file);
		if (!is_int($size) || $size < 22) {
			throw new \RuntimeException('Invalid ZIP file');
		}
		$length = min($size, 65557);
		fseek($zip, $size - $length);
		$tail = fread($zip, $length);
		$position = strrpos($tail, "\x50\x4b\x05\x06");
		if ($position === false || strlen($tail) < $position + 22) {
			throw new \RuntimeException('ZIP end-of-central-directory record not found');
		}
		$data = unpack(
			'vdisk/vdisk_start/ventries_disk/ventries/Vsize/Voffset/vcomment',
			substr($tail, $position + 4, 18)
		);
		if ($data['disk'] !== 0 || $data['disk_start'] !== 0 || $data['entries_disk'] !== $data['entries']) {
			throw new \RuntimeException('Multi-disk ZIP archives are not supported');
		}
		return ['entries' => $data['entries'], 'size' => $data['size'], 'offset' => $data['offset']];
	}

	/**
	 * @param resource $zip
	 */
	private function centralHeader($zip): array
	{
		$bin = fread($zip, 46);
		if (strlen($bin) !== 46) throw new \RuntimeException('Truncated ZIP central header');
		$h = unpack('Vsignature/vmade/vneeded/vflag/vcmpr/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', $bin);
		if ($h['signature'] !== 0x02014b50) throw new \RuntimeException('Invalid ZIP central header signature');
		$h['name'] = $h['nlen'] ? fread($zip, $h['nlen']) : '';
		// 跳过 extra 与 comment
		$skip = $h['elen'] + $h['clen'];
		if ($skip > 0) fread($zip, $skip);
		return $h;
	}

	/**
	 * @param resource $zip
	 */
	private function fileHeader($zip): array
	{
		$bin = fread($zip, 30);
		if (strlen($bin) !== 30) throw new \RuntimeException('Truncated ZIP local header');
		$h = unpack('Vsignature/vneeded/vflag/vcmpr/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', $bin);
		if ($h['signature'] !== 0x04034b50) throw new \RuntimeException('Invalid ZIP local header signature');
		$h['name'] = $h['nlen'] ? fread($zip, $h['nlen']) : '';
		if ($h['elen'] > 0) fread($zip, $h['elen']);
		return $h;
	}

	/**
	 * @param resource $zip
	 */
	private function extractFile(array $hdr, string $dest, $zip): void
	{
		// 目录
		if (substr($hdr['name'], -1) === '/') {
			$this->ensureDir($dest . $hdr['name']);
			return;
		}

		$this->ensureDir($dest . dirname($hdr['name']));
		$target = $dest . $hdr['name'];

		$out = fopen($target, 'wb');
		if (!$out) throw new \RuntimeException('Unable to create extracted ZIP entry');
		$hash = hash_init('crc32b');
		$written = 0;

		try {
			$remaining = $hdr['csize'];
			$inflate = $hdr['cmpr'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
			while ($remaining > 0) {
				$chunk = fread($zip, min($remaining, 65536));
				if ($chunk === false || $chunk === '') throw new \RuntimeException('Truncated ZIP entry data');
				$remaining -= strlen($chunk);
				$data = $inflate === null
					? $chunk
					: inflate_add($inflate, $chunk, $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
				if ($data === false) throw new \RuntimeException('ZIP inflate failed');
				if ($data !== '') {
					$length = strlen($data);
					if (fwrite($out, $data) !== $length) throw new \RuntimeException('ZIP extraction write failed');
					hash_update($hash, $data);
					$written += $length;
				}
			}
			if ($written !== $hdr['usize']
				|| strtolower(hash_final($hash)) !== strtolower(sprintf('%08x', $hdr['crc']))) {
				throw new \RuntimeException('ZIP entry size or CRC verification failed');
			}
			fflush($out);
		} catch (\Throwable $e) {
			fclose($out);
			@unlink($target);
			throw $e;
		}
		fclose($out);
		touch($target, $this->dos2unixtime($hdr['date'], $hdr['time']));
	}

	private function dos2unixtime(int $d, int $t): int
	{
		return mktime(
			($t & 0xF800) >> 11,
			($t & 0x07E0) >> 5,
			($t & 0x001F) << 1,
			($d & 0x01E0) >> 5,
			($d & 0x001F),
			(($d & 0xFE00) >> 9) + 1980
		);
	}
}
