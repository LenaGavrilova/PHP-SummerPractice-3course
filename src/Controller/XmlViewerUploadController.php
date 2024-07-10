<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XmlViewerUploadController extends AbstractController
{
    #[Route('/view-xml-upload', name: 'view_xml_upload')]
    public function uploadAndDisplayXml(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            /** @var UploadedFile $xmlFile */
            $xmlFile = $request->files->get('xml_file');
            if ($xmlFile) {
                try {
                    // Сохраняем файл в директорию временных файлов
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads';
                    $xmlFileName = uniqid() . '.' . $xmlFile->guessExtension();
                    $xmlFile->move($uploadDir, $xmlFileName);

                    // Сохраняем путь к файлу в сессию
                    $session = $request->getSession();
                    $session->set('xml_file_path', $uploadDir . '/' . $xmlFileName);

                    return $this->redirectToRoute('view_xml');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Ошибка при обработке XML файла.');
                }
            } else {
                $this->addFlash('error', 'Файл не загружен.');
            }
        }

        // Если файл не загружен или произошла ошибка
        return $this->render('xml_viewer/upload_xml.html.twig');
    }
}
