<?php

namespace App\Controller;

use App\Service\XsdService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XsdController extends AbstractController
{
    private XsdService $xsdService;

    public function __construct(XsdService $xsdService)
    {
        $this->xsdService = $xsdService;
    }


    #[Route('/xsd/view/{path}', name: 'xsd_view', requirements: ["path" => ".*"])]
    public function index(Request $request, string $path = ""): Response
    {
        $resource = $this->xsdService->getResource($path);

        if ($resource === false) {
            return new Response("Ресурс не найден!", Response::HTTP_NOT_FOUND);
        }

        if (gettype($resource) === "string") {
            return new Response(file_get_contents($resource), Response::HTTP_OK, [
                'Content-Type' => mime_content_type($resource),
            ]);
        }

        return $this->render('xsd/index.html.twig', ["path" => $path, "files" => $resource]);
    }

    #[Route('/xsd/upload/{path}', name: 'xsd_upload', requirements: ["path" => ".*"], methods: ["POST"])]
    public function upload(Request $request, string $path): Response
    {
        /** @var UploadedFile $xsdFile */
        $xsdFile = $request->files->get('xsd');

        try {
            $this->xsdService->uploadXsdFile($xsdFile, $path);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute("xsd_view", ["path" => $path]);
    }

    #[Route('/xsd/create-dir/{path}', name: 'xsd_create_dir', requirements: ["path" => ".*"], methods: ["POST"])]
    public function createDirectory(Request $request, string $path): Response
    {
        $dirName = $request->request->get("dirname");

        try {
            $this->xsdService->createDir($path, $dirName);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute("xsd_view", ["path" => $path]);
    }

    #[Route('/xsd/delete/{path}', name: 'xsd_delete', requirements: ["path" => ".*"], methods: ["POST"])]
    public function deleteFile(Request $request, string $path): Response
    {
        try {
            $parentPath = $this->xsdService->deleteFile($path);
        } catch (\Exception $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->redirectToRoute("xsd_view", ["path" => $parentPath]);
    }
}
