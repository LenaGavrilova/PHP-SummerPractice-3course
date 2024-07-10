<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class XsdController extends AbstractController
{
    public const XSD_DIR = "/public/xsd/";


    #[Route('/xsd/view/{path}   ', name: 'xsd_view', requirements: ["path" => ".*"])]
    public function index(Request $request, string $path): Response
    {
        $fullPath = $this->getParameter('kernel.project_dir') . self::XSD_DIR . $path;

        if (!file_exists($fullPath)) {
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
}
