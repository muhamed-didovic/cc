<?php

namespace App;


use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

class FileLister
{
    public $file;

    public function __construct()
    {
        // The internal adapter
        $adapter = new LocalFilesystemAdapter(
        // Determine root directory
            getenv('DOWNLOAD_FOLDER')
        );
    
        // The FilesystemOperator
        $this->file = new Filesystem($adapter);
    }

    public function all()
    {
        $files = collect($this->file->listContents('', true));
        $all = [];
        foreach ($files as $file) {
            if (substr($file['basename'], 0, 1) == '.') {
                continue;
            }

            if ($file['type'] == 'dir' && ! isset($all[$file['basename']])) {
                $all[$file['basename']] = [];
            }

            if ($file['type'] == 'file') {
                $series = substr($file['path'], 0, -(strlen($file['basename']) + 1));
                if (! isset($all[$series])) {
                    $all[$series] = [];
                }
                array_push($all[$series], $file['basename']);
            }
        }

        return collect($all);
    }

    /**
     * @return static
     */
    public function series()
    {
        return $this->all()->keys();
    }

    public function exists($folder)
    {
        return $this->file->fileExists($folder);
    }

    /**
     * @param bool $lesson
     *
     * @return \Illuminate\Support\Collection|\Tightenco\Collect\Support\Collection
     */
    public function lessons($lesson = false)
    {
        if ($lesson) {
            return collect($this->all()->keyBy($lesson)->first());
        }

        return $this->all()->values();
    }
}
