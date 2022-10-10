<?php
require_once $_SERVER["DOCUMENT_ROOT"].'/_h5ai/private/php/ext/zipstream/src/ZipStream.php';
require_once $_SERVER["DOCUMENT_ROOT"].'/_h5ai/private/php/ext/zipstream/src/Option/Archive.php';
class Archive {
    const NULL_BYTE = "\0";

    private static $SEGMENT_SIZE = 16777216;  // 1024 * 1024 * 16 = 16MiB
    private static $TAR_PASSTHRU_CMD = 'cd [ROOTDIR] && tar --no-recursion -c -- [DIRS] [FILES]';
    private static $ZIP_PASSTHRU_CMD = 'cd [ROOTDIR] && zip - -- [FILES]';

    private $context;
    private $base_path;
    private $dirs;
    private $files;

    public function __construct($context) {
        $this->context = $context;
    }

    public function streamzipoutput($as, $type, $base_href, $hrefs) {

        //在原作者的基础上修改的，增加stream-zip下载，不占用存储空间，直接以zip流的方式下载
        //主要目的：为了解决某些号称免费无限空间和无限带宽web主机单个文件配额无法超过10-15M从而导致无法正常打包下载的问题
        //(因为正常打包是非流的形式，会占用空间，但是它的空间单文件配额是不允许你大于10-15M文件存在的，因此导致打包失败。)

        $this->base_path = $this->context->to_path($base_href);
        if (!$this->context->is_managed_path($this->base_path)) {
            return false;
        }

        $this->dirs = [];
        $this->files = [];

        $this->add_hrefs($hrefs);

        if (count($this->dirs) === 0 && count($this->files) === 0) {
            if ($type === 'php-tar') {
                $this->add_dir($this->base_path, '/');
            } else {
                $this->add_dir($this->base_path, '.');
            }
        }

        return $this->stream_zip($as, $this->files);
    }

    public function output($type, $base_href, $hrefs) {
        $this->base_path = $this->context->to_path($base_href);
        if (!$this->context->is_managed_path($this->base_path)) {
            return false;
        }

        $this->dirs = [];
        $this->files = [];

        $this->add_hrefs($hrefs);

        if (count($this->dirs) === 0 && count($this->files) === 0) {
            if ($type === 'php-tar') {
                $this->add_dir($this->base_path, '/');
            } else {
                $this->add_dir($this->base_path, '.');
            }
        }

        if ($type === 'php-tar') {
            return $this->php_tar($this->dirs, $this->files);
        } elseif ($type === 'shell-tar') {
            return $this->shell_cmd(Archive::$TAR_PASSTHRU_CMD);
        } elseif ($type === 'shell-zip') {
            return $this->shell_cmd(Archive::$ZIP_PASSTHRU_CMD);
        }
        return false;
    }

    private function stream_zip($as, $files): bool {

        //目标zip文件
        $fp = fopen("php://output", "wb");

        $options = new ZipStream\Option\Archive();

        // enable output of HTTP headers
        $options->setSendHttpHeaders(true);
        $options->setContentDisposition("attachment");
        $options->setContentType("application/x-zip");

        $options->setDeflateLevel(-1);
        $options->setEnableZip64(false);
        $options->setFlushOutput(true);
        $options->setLargeFileSize(1000000);
        $options->setLargeFileMethod(ZipStream\Option\Method::STORE());
        $options->setStatFiles(true);
        $options->setOutputStream($fp);

        $zip = new ZipStream\ZipStream($as, $options);

        foreach ($files as $real_file => $archived_file) {
            $zip->addFileFromPath($archived_file, $real_file);
        }

        // finish the zip stream
        $zip->finish();

        fclose($fp);

        return true;
    }

    private function shell_cmd($cmd) {
        $cmd = str_replace('[ROOTDIR]', escapeshellarg($this->base_path), $cmd);
        $cmd = str_replace('[DIRS]', count($this->dirs) ? implode(' ', array_map('escapeshellarg', $this->dirs)) : '', $cmd);
        $cmd = str_replace('[FILES]', count($this->files) ? implode(' ', array_map('escapeshellarg', $this->files)) : '', $cmd);
        try {
            Util::passthru_cmd($cmd);
        } catch (Exeption $err) {
            return false;
        }
        return true;
    }

    private function php_tar($dirs, $files) {
        $filesizes = [];
        $total_size = 512 * count($dirs);
        foreach (array_keys($files) as $real_file) {
            $size = filesize($real_file);

            $filesizes[$real_file] = $size;
            $total_size += 512 + $size;
            if ($size % 512 != 0) {
                $total_size += 512 - ($size % 512);
            }
        }

        header('Content-Length: ' . $total_size);

        foreach ($dirs as $real_dir => $archived_dir) {
            echo $this->php_tar_header($archived_dir, 0, @filemtime($real_dir . DIRECTORY_SEPARATOR . '.'), 5);
        }

        foreach ($files as $real_file => $archived_file) {
            $size = $filesizes[$real_file];

            echo $this->php_tar_header($archived_file, $size, @filemtime($real_file), 0);
            $this->print_file($real_file);

            if ($size % 512 != 0) {
                echo str_repeat(Archive::NULL_BYTE, 512 - ($size % 512));
            }
        }

        return true;
    }

    private function php_tar_header($filename, $size, $mtime, $type) {
        $name = substr(basename($filename), -99);
        $prefix = substr(Util::normalize_path(dirname($filename)), -154);
        if ($prefix === '.') {
            $prefix = '';
        }

        $header =
            str_pad($name, 100, Archive::NULL_BYTE)  // filename [100]
            . '0000755' . Archive::NULL_BYTE  // file mode [8]
            . '0000000' . Archive::NULL_BYTE  // uid [8]
            . '0000000' . Archive::NULL_BYTE  // gid [8]
            . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . Archive::NULL_BYTE  // file size [12]
            . str_pad(decoct($mtime), 11, '0', STR_PAD_LEFT) . Archive::NULL_BYTE  // file modification time [12]
            . '        '  // checksum [8]
            . str_pad($type, 1)  // file type [1]
            . str_repeat(Archive::NULL_BYTE, 100)  // linkname [100]
            . 'ustar' . Archive::NULL_BYTE  // magic [6]
            . '00'  // version [2]
            . str_repeat(Archive::NULL_BYTE, 80)  // uname, gname, defmajor, devminor [32 + 32 + 8 + 8]
            . str_pad($prefix, 155, Archive::NULL_BYTE)  // filename [155]
            . str_repeat(Archive::NULL_BYTE, 12);  // fill [12]
        assert(strlen($header) === 512);

        // checksum
        $checksum = array_sum(array_map('ord', str_split($header)));
        $checksum = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . Archive::NULL_BYTE . ' ';
        $header = substr_replace($header, $checksum, 148, 8);

        return $header;
    }

    private function print_file($file) {
        // Send file content in segments to not hit PHP's memory limit (default: 128M)
        if ($fd = fopen($file, 'rb')) {
            while (!feof($fd)) {
                print fread($fd, Archive::$SEGMENT_SIZE);
                @ob_flush();
                @flush();
            }
            fclose($fd);
        }
    }

    private function add_hrefs($hrefs) {
        if (!is_array($hrefs)) {
            $hrefs = array($hrefs);
        }

        foreach ($hrefs as $href) {
            if (trim($href) === '') {
                continue;
            }

            $href = Util::normalize_path($href, false);
            $d = dirname($href);
            $n = basename($href);

            if ($this->context->is_managed_href($d) && !$this->context->is_hidden($n)) {

                $real_file = $this->context->to_path($href);
                $archived_file = preg_replace('!^' . preg_quote(Util::normalize_path($this->base_path, true)) . '!', '', $real_file);

                if (is_dir($real_file)) {
                    $this->add_dir($real_file, $archived_file);
                } else {
                    $this->add_file($real_file, $archived_file);
                }
            }
        }
    }

    private function add_file($real_file, $archived_file) {
        if (is_readable($real_file)) {
            $this->files[$real_file] = $archived_file;
        }
    }

    private function add_dir($real_dir, $archived_dir) {
        if ($this->context->is_managed_path($real_dir)) {
            $this->dirs[$real_dir] = $archived_dir;

            $files = $this->context->read_dir($real_dir);
            foreach ($files as $file) {
                $real_file = $real_dir . '/' . $file;
                $archived_file = $archived_dir . '/' . $file;

                if (is_dir($real_file)) {
                    $this->add_dir($real_file, $archived_file);
                } else {
                    $this->add_file($real_file, $archived_file);
                }
            }
        }
    }
}
