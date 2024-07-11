<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XsdService
{
    public const XSD_DIR = "/static/xsd/";

    // Files to be ignored (not returned and not viewed if directory)
    public const IGNORE_PATHS = ["/.gitignore"];

    private string $projectDir;

    public function __construct(KernelInterface $appKernel)
    {
        $this->projectDir = $appKernel->getProjectDir();
    }

    /**
     * Get resource by relative path
     * If there is no such resource, return false
     * If this is a regular file, return its path
     * If this is a directory, return array of directories and files inside it
     * @param string $relativePath
     * @return string|array|false
     */
    public function getResource(string $relativePath): string|array|false
    {
        $fileSystemPath = $this->getXsdFileSystemPath($relativePath);

        // If file doesn't exist or if it should be ignored
        if (!file_exists($fileSystemPath) || in_array($relativePath, self::IGNORE_PATHS)) {
            return false;
        }

        // If this is a regular file
        if (is_file($fileSystemPath)) {
            return $fileSystemPath;
        }

        // This is a directory
        return $this->scanDirectory($fileSystemPath, $relativePath);
    }

    public function uploadXsdFile(UploadedFile $xsdFile, string $dirPathRelative): string
    {
        $xsdContent = file_get_contents($xsdFile->getPathname());

        if (empty($xsdContent)) {
            throw new \RuntimeException("Некорректный файл");
        }

        try {
            // Saving file
            $uploadDir = $this->getXsdFileSystemPath($dirPathRelative);
            $xsdFile->move($uploadDir, $xsdFile->getClientOriginalName());
        } catch (\Exception $e) {
            throw new \RuntimeException("Ошибка при обработке файла");
        }

        return $dirPathRelative . "/" . $xsdFile->getFilename();
    }

    public function createDir(string $basePath, string $dirName): string
    {
        if (empty($dirName)) {
            throw new \RuntimeException("Некорректное название директории");
        }

        try {
            // Creating dir
            $fullPath = $this->normalizePath($this->getXsdFileSystemPath($basePath) . "/" . $dirName);
            mkdir($fullPath);
        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка при создании директории');
        }

        return $basePath . "/" . $dirName;
    }

    public function deleteFile(string $path): string
    {
        if ($path === "") {
            throw new \RuntimeException('Невозможно удалить этот файл/директорию');
        }
        $fullPath = $this->getXsdFileSystemPath($path);

        if (!file_exists($fullPath) || in_array($path, self::IGNORE_PATHS)) {
            throw new \RuntimeException('File or directory not found.');
        }

        if (is_file($fullPath)) {
            // File
            unlink($fullPath);
        } else {
            $this->removeDir($fullPath);
        }

        $pathExplode = explode('/', $path);
        array_pop($pathExplode);
        $parentPath = implode('/', $pathExplode);

        return $parentPath;
    }

    public function normalizePath(string $path): string
    {
        $s = array_reduce(explode('/', $path), function ($a, $b) {
            if ($a === null) {
                $a = "/";
            }
            if ($b === "" || $b === ".") {
                return $a;
            }
            if ($b === "..") {
                return dirname($a);
            }

            return preg_replace("/\/+/", "/", "$a/$b");
        });

        return $s;
    }

    public function getXsdFileSystemPath($path): string
    {
        return $this->projectDir . self::XSD_DIR . $path;
    }

    public function scanDirectory($dirPathFileSystem, $dirPathRelative)
    {
        $fileNames = scandir($dirPathFileSystem);

        $dirs = [];
        $regularFiles = [];

        foreach ($fileNames as $fileName) {
            $filePathFileSystem = $dirPathFileSystem . "/" . $fileName;
            $filePathRelative = $this->normalizePath($dirPathRelative . "/" . $fileName);

            if (in_array($filePathRelative, self::IGNORE_PATHS)) {
                continue;
            }

            $fileArray = [
                'name' => $fileName,
                'path' => $filePathRelative
            ];

            if (str_starts_with($fileArray["path"], "/")) {
                $fileArray["path"] = substr($fileArray["path"], 1);
            }
            if (is_dir($filePathFileSystem)) {
                $fileArray["type"] = "dir";
                $dirs[] = $fileArray;
            } else {
                $fileArray["type"] = "file";
                $regularFiles[] = $fileArray;
            }
        }

        // If this is top directory (if there is no parent directory), make ".." return itself
        if ($dirPathRelative === "") {
            for ($i = 0; $i < count($dirs); $i++) {
                if ($dirs[$i]["name"] == "..") {
                    $dirs[$i]["path"] = $dirPathRelative;
                }
            }
        }

        $allFiles = array_merge($dirs, $regularFiles);

        return $allFiles;
    }

    private function removeDir(string $dir): void
    {
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator(
            $it,
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
