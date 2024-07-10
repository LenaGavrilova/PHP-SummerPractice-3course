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
        $fullPath = $this->getParameter('kernel.project_dir') . self::XSD_DIR . $path;

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
            $filePath = $fullPath . $file;
            if (is_dir($filePath)) {
                $dirs[] = $file;
            } else {
                $regularFiles[] = $file;
            }
        }

        // If this is top directory (no parent)
        foreach (array_keys($dirs, "..", true) as $key) {
            unset($dirs[$key]);
        }

        return $this->render('xsd/index.html.twig', ["path" => $path, "dirs" => $dirs, "files" => $regularFiles]);
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
            $uploadDir = $this->getParameter('kernel.project_dir') . self::XSD_DIR;
            $xsdFile->move($uploadDir, $xsdFile->getClientOriginalName());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка при обработке XML файла.');
        }

        return $this->redirectToRoute("xsd_view", ["path" => $path]);
    }
}
