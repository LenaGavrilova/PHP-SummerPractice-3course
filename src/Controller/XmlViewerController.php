<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class XmlViewerController extends AbstractController
{
    #[Route('/view-xml', name: 'view_xml')]
    public function viewXml(Request $request): Response
    {
        $session = $request->getSession();
        $xmlFilePath = $session->get('xml_file_path');

        if ($xmlFilePath && file_exists($xmlFilePath)) {
            $xmlContent = file_get_contents($xmlFilePath);
            return $this->render('xml_viewer/view_xml.html.twig', [
                'xmlContent' => $xmlContent,
            ]);
        } else {
            $this->addFlash('error', 'XML файл не найден.');
            return $this->redirectToRoute('view_xml_upload');
        }
    }
}
