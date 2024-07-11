<?php

namespace App\Controller;

use App\Service\XsdService;
use GoetasWebservices\XML\XSDReader\Schema\Element\ElementDef;
use GoetasWebservices\XML\XSDReader\Schema\Schema;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use GoetasWebservices\XML\XSDReader\SchemaReader;

use DOMDocument;
use DOMXPath;

class XmlViewerController extends AbstractController
{
    private XsdService $xsdService;
    private SchemaReader $schemaReader;
    public function __construct(XsdService $xsdService)
    {
        $this->xsdService = $xsdService;
        $this->schemaReader = new SchemaReader();
    }

    #[Route('/view-xml-upload', name: 'view_xml_upload', methods: ['GET'])]
    public function viewXmlUploadForm(Request $request): Response
    {
        // Render form for xml upload
        return $this->render('xml_viewer/upload_xml.html.twig');
    }

    #[Route('/view-xml', name: 'view_xml', methods: ['POST'])]
    public function viewXmlUploadFormPost(Request $request): Response
    {
        // Get XML and XSD files, show XML file

        /** @var UploadedFile $xmlFile */
        $xmlFile = $request->files->get('xml_file');
        $xsdPath = $request->request->get("xsd_path");

        $xsdPathFileSystem = $this->xsdService->getXsdFileSystemPath($xsdPath);

        // Load XML file
        $xmlDom = new DOMDocument();
        $xmlDom->load($xmlFile);

        // Validate XML according to XSD
        libxml_use_internal_errors(true);
        $xmlDom->schemaValidate($xsdPathFileSystem);
        if (libxml_get_errors()) {
            $errorMessages = [];
            foreach (libxml_get_errors() as $error) {
                $errorMessages[] = $error->message;
            }
            libxml_clear_errors();
            return $this->render('xml_viewer/view_xml.html.twig', [
                'errors' => $errorMessages
            ]);
        }

        // Get schema using schemaReader
        $schema = $this->schemaReader->readFile($xsdPathFileSystem);
        // $xml = $this->schemaReader->readString($xmlFile->getContent());

        // Find element of this XML in the XSD
        $tagNameExplode = explode(":", $xmlDom->documentElement->tagName);
        $rootElementTagNameNoNamespace = array_pop($tagNameExplode);
        $schemaElement = null;
        foreach ($schema->getElements() as $element) {
            // if tag name AND namespace URI are the same
            if ($element->getName() === $rootElementTagNameNoNamespace && $xmlDom->documentElement->namespaceURI === $element->getSchema()->getTargetNamespace()) {
                $schemaElement = $element;
                break;
            }
        }
        if ($schemaElement === null) {
            return $this->render('xml_viewer/view_xml.html.twig', [
                'errors' => ["Не удалось найти корневой элемент XML в схеме XSD"]
            ]);
        }

        $html = $this->parseXml($xmlDom, $schemaElement);

        return $this->render('xml_viewer/view_xml.html.twig', [
            'xml' => $html
        ]);
    }

    private function parseXml(DOMDocument $xmlDom, ElementDef $schemaElement)
    {
        $html = '<pre>';

        $this->processNode($xmlDom->getRootNode(), $schemaElement, $html, 1);

        $html .= '</pre>';

        return $html;
    }

    private function processNode(\DOMNode $currentNode, ElementDef $schemaElement, string &$html, int $depth)
    {
        $tab = str_repeat(" ", 2 * $depth);
        $html .= $tab . "<b>" . $currentNode->localName . "</b>";
        if (!$currentNode->hasChildNodes()) {
            $html .= $currentNode->textContent;
        }
        $html .= "\n";

        foreach ($currentNode->childNodes as $node) {
            $this->processNode($node, $schemaElement, $html, $depth + 1);
        }
    }
}
