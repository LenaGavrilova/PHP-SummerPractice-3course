<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XsdController extends AbstractController
{
    public const XSD_DIR = "/static/xsd/";

    // Files to be ignored (not returned and not viewed if directory)
    public const IGNORE_FILES = [".gitignore"];


    #[Route('/xsd/view/{path}', name: 'xsd_view', requirements: ["path" => ".*"])]
    public function index(Request $request, string $path): Response
    {
        $fullPath = $this->getFileSystemPath($path);

        if (!file_exists($fullPath) || in_array($path, self::IGNORE_FILES)) {
            return new Response('File or directory not found.', Response::HTTP_NOT_FOUND);
        }

        if (is_file($fullPath)) {
            // File
            return new Response(file_get_contents($fullPath), Response::HTTP_OK, [
                'Content-Type' => mime_content_type($fullPath),
            ]);
        }

        // Directory
        $files = scandir($fullPath);

        $dirs = [];
        $regularFiles = [];

        foreach ($files as $file) {
            if (in_array($file, self::IGNORE_FILES)) {
                continue;
            }
            $filePath = $path . "/" . $file;
            $fileArray = [
                'name' => $file,
                'path' => $this->normalizePath($filePath)
            ];
            if (str_starts_with($fileArray["path"], "/")) {
                $fileArray["path"] = substr($fileArray["path"], 1);
            }
            if (is_dir($filePath)) {
                $dirs[] = $fileArray;
            } else {
                $regularFiles[] = $fileArray;
            }
        }

        // If this is top directory (if there is no parent directory), make ".." return itself
        if ($path === "") {
            for ($i = 0; $i < count($dirs); $i++) {
                if ($dirs[$i]["name"] == "..") {
                    $dirs[$i]["path"] = $path;
                }
            }
        }

        $allFiles = array_merge($dirs, $regularFiles);

        return $this->render('xsd/index.html.twig', ["path" => $path, "files" => $allFiles]);
    }

    #[Route('/xsd/upload/{path}', name: 'xsd_upload', requirements: ["path" => ".*"], methods: ["POST"])]
    public function upload(Request $request, string $path): Response
    {
        /** @var UploadedFile $xsdFile */
        $xsdFile = $request->files->get('xsd');
        $xsdContent = file_get_contents($xsdFile->getPathname());

        if (empty($xsdContent)) {
            return $this->render('xsd/index.html.twig', [
                'error' => 'Пожалуйста, прикрепите XSD файл',
            ]);
        }

        try {
            // Saving file
            $uploadDir = $this->getFileSystemPath($path);
            $xsdFile->move($uploadDir, $xsdFile->getClientOriginalName());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка при обработке XML файла.');
        }

        return $this->redirectToRoute("xsd_view", ["path" => $path]);
    }

    #[Route('/xsd/create-dir/{path}', name: 'xsd_create_dir', requirements: ["path" => ".*"], methods: ["POST"])]
    public function createDirectory(Request $request, string $path): Response
    {
        $dirName = $request->request->get("dirname");

        if (empty($dirName)) {
            return $this->render('xsd/index.html.twig', [
                'error' => 'Пожалуйста, укажите корректное название директории',
            ]);
        }

        try {
            // Creating dir
            $fullPath = $this->normalizePath($this->getFileSystemPath($path) . "/" . $dirName);
            mkdir($fullPath);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка при создании директории');
        }

        return $this->redirectToRoute("xsd_view", ["path" => $path]);
    }

    #[Route('/xsd/delete/{path}', name: 'xsd_delete', requirements: ["path" => ".*"], methods: ["POST"])]
    public function deleteFile(Request $request, string $path): Response
    {
        if ($path === "") {
            return new Response('Невозможно удалить этот файл/директорию', Response::HTTP_BAD_REQUEST);
        }
        $fullPath = $this->getFileSystemPath($path);

        if (!file_exists($fullPath) || in_array($path, self::IGNORE_FILES)) {
            return new Response('File or directory not found.', Response::HTTP_NOT_FOUND);
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

        return $this->redirectToRoute("xsd_view", ["path" => $parentPath]);
    }

    private function getFileSystemPath($path): string
    {
        return $this->getParameter('kernel.project_dir') . self::XSD_DIR . "/" . $path;
    }

    private function normalizePath(string $path): string
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
